<?php
// includes/sheets.php — Google Sheets API v4 helper (pure PHP, no Composer needed)
// Uses service-account or OAuth token.json already obtained via cnp_leads.py flow.

require_once __DIR__ . '/../config.php';

class SheetsAPI {

    private string $accessToken = '';

    public function __construct() {
        $this->accessToken = $this->getToken();
    }

    // ── Token management ────────────────────────────────────────────────────
    private function getToken(): string {
        if (!file_exists(GOOGLE_TOKEN_FILE)) {
            throw new RuntimeException('token.json not found. Run the Python auth script first.');
        }
        $data = json_decode(file_get_contents(GOOGLE_TOKEN_FILE), true);

        // Refresh if expired
        if (!empty($data['expiry']) && strtotime($data['expiry']) < time() + 60) {
            $data = $this->refreshToken($data);
        }
        return $data['token'] ?? $data['access_token'] ?? '';
    }

    private function refreshToken(array $data): array {
        $resp = $this->http('POST', 'https://oauth2.googleapis.com/token', [], [
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $data['refresh_token'],
            'grant_type'    => 'refresh_token',
        ], false);
        $data['token']      = $resp['access_token'];
        $data['access_token'] = $resp['access_token'];
        $data['expiry']     = date('c', time() + ($resp['expires_in'] ?? 3600));
        file_put_contents(GOOGLE_TOKEN_FILE, json_encode($data));
        return $data;
    }

    // ── Core HTTP ────────────────────────────────────────────────────────────
    private function http(string $method, string $url, array $query = [], $body = null, bool $auth = true): array {
        if ($query) $url .= '?' . http_build_query($query);
        $headers = ['Content-Type: application/json'];
        if ($auth) $headers[] = 'Authorization: Bearer ' . $this->accessToken;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS,
                is_array($body) ? json_encode($body) : http_build_query($body)
            );
        }
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode($raw, true) ?? [];
    }

    // ── Read range ───────────────────────────────────────────────────────────
    public function getRange(string $sheetId, string $range): array {
        $url  = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/" . urlencode($range);
        $data = $this->http('GET', $url);
        return $data['values'] ?? [];
    }

    // ── Sync helpers ─────────────────────────────────────────────────────────

    /**
     * Sync Website leads (CNP Leads tab)
     * Columns: Sr, Project, First, Last, Mobile, Email, Pref, Date, Time, URL
     */
    public function syncWebsite(): int {
        $rows = $this->getRange(SHEET_WEBSITE_ID, SHEET_WEBSITE_TAB . '!A2:J');
        return $this->upsertLeads($rows, 'website', function(array $r): array {
            return [
                'source_row_id' => $r[0] ?? '',
                'project_name'  => trim($r[1] ?? ''),
                'first_name'    => trim($r[2] ?? ''),
                'last_name'     => trim($r[3] ?? ''),
                'mobile'        => trim($r[4] ?? ''),
                'email'         => trim($r[5] ?? ''),
                'preference'    => trim($r[6] ?? ''),
                'sheet_date'    => trim($r[7] ?? ''),
                'sheet_time'    => trim($r[8] ?? ''),
                'page_url'      => trim($r[9] ?? ''),
            ];
        });
    }

    /**
     * Sync Meta leads (LSR Leads 2026 tab)
     * Adjust column mapping to match your actual sheet headers.
     */
    public function syncMeta(): int {
        $rows = $this->getRange(SHEET_META_ID, SHEET_META_TAB . '!A2:J');
        return $this->upsertLeads($rows, 'meta', function(array $r): array {
            return [
                'source_row_id' => $r[0] ?? '',
                'project_name'  => trim($r[1] ?? ''),
                'first_name'    => trim($r[2] ?? ''),
                'last_name'     => trim($r[3] ?? ''),
                'mobile'        => trim($r[4] ?? ''),
                'email'         => trim($r[5] ?? ''),
                'preference'    => trim($r[6] ?? ''),
                'sheet_date'    => trim($r[7] ?? ''),
                'sheet_time'    => trim($r[8] ?? ''),
                'page_url'      => trim($r[9] ?? ''),
            ];
        });
    }

    /**
     * Sync Google leads tab
     */
    public function syncGoogle(): int {
        $rows = $this->getRange(SHEET_GOOGLE_ID, SHEET_GOOGLE_TAB . '!A2:J');
        return $this->upsertLeads($rows, 'google', function(array $r): array {
            return [
                'source_row_id' => $r[0] ?? '',
                'project_name'  => trim($r[1] ?? ''),
                'first_name'    => trim($r[2] ?? ''),
                'last_name'     => trim($r[3] ?? ''),
                'mobile'        => trim($r[4] ?? ''),
                'email'         => trim($r[5] ?? ''),
                'preference'    => trim($r[6] ?? ''),
                'sheet_date'    => trim($r[7] ?? ''),
                'sheet_time'    => trim($r[8] ?? ''),
                'page_url'      => trim($r[9] ?? ''),
            ];
        });
    }

    /**
     * Fetch Sales Team names from sheet
     */
    public function getSalesTeam(): array {
        $rows = $this->getRange(SHEET_WEBSITE_ID, SHEET_SALES_TAB . '!A2:C');
        $team = [];
        foreach ($rows as $r) {
            if (!empty($r[0])) {
                $team[] = [
                    'name'  => trim($r[0]),
                    'email' => trim($r[1] ?? ''),
                    'phone' => trim($r[2] ?? ''),
                ];
            }
        }
        return $team;
    }

    // ── Upsert into DB ───────────────────────────────────────────────────────
    private function upsertLeads(array $rows, string $source, callable $mapper): int {
        $count = 0;
        $pdo   = db();

        foreach ($rows as $row) {
            if (empty($row) || (count($row) === 1 && empty($row[0]))) continue;
            $d = $mapper($row);
            if (empty($d['mobile']) && empty($d['email'])) continue;

            // Get or create project
            $projectId = null;
            if (!empty($d['project_name'])) {
                $s = $pdo->prepare('SELECT id FROM projects WHERE name=?');
                $s->execute([$d['project_name']]);
                $proj = $s->fetch();
                if (!$proj) {
                    $pdo->prepare('INSERT IGNORE INTO projects (name) VALUES (?)')->execute([$d['project_name']]);
                    $projectId = (int)$pdo->lastInsertId();
                } else {
                    $projectId = (int)$proj['id'];
                }
            }

            // Check duplicate by source + row id, or by mobile
            $existing = null;
            if (!empty($d['source_row_id'])) {
                $s = $pdo->prepare('SELECT id FROM leads WHERE source=? AND source_row_id=?');
                $s->execute([$source, $d['source_row_id']]);
                $existing = $s->fetch();
            }
            if (!$existing && !empty($d['mobile'])) {
                $s = $pdo->prepare('SELECT id FROM leads WHERE source=? AND mobile=?');
                $s->execute([$source, $d['mobile']]);
                $existing = $s->fetch();
            }

            if (!$existing) {
                $ins = $pdo->prepare('INSERT INTO leads
                    (source,source_row_id,project_id,first_name,last_name,mobile,email,preference,sheet_date,sheet_time,page_url)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $ins->execute([
                    $source, $d['source_row_id'], $projectId,
                    $d['first_name'], $d['last_name'], $d['mobile'], $d['email'],
                    $d['preference'], $d['sheet_date'], $d['sheet_time'], $d['page_url'],
                ]);
                $count++;
            }
        }

        // Log sync
        $pdo->prepare('INSERT INTO sync_log (source, rows_synced) VALUES (?,?)')->execute([$source, $count]);
        return $count;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  CNP Greenfield Sheet Sync — Multi-project horizontal layout
    //  Sheet: 1smfb0vmFW3gaH9gbN7Jsze9cyA-t22ftunL2tX6NaQA
    //  7 projects arranged side-by-side in columns
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Sync ALL leads from the CNP Greenfield Google Sheet.
     * This sheet has a non-standard layout with multiple projects arranged
     * horizontally (side by side in columns).
     *
     * Projects & column offsets:
     *   Elevate Shop (0-6), Athena (8-14), Studio Apartment (16-22),
     *   One Holding Kharadi (24-30), Jhamtani Ace Abundance (32-39),
     *   Jhamtani Spacebiz Baner (41-47), Azalea/Athena (49-54)
     */
    /**
     * Map any raw CNP section / Meta form name to the official master project name.
     */
    private function masterProjectName(string $rawName): string {
        $map = [
            'Elevate Shop'                         => 'Jhamtani Elevate Shop',
            'Athena'                               => 'Athena Project',
            'Studio Apartment'                     => 'ONE Suites',
            'One Holding Kharadi Studio Apartment' => 'ONE Suites',
            'One Holding Kharadi'                  => 'ONE Suites',
            'One Holding'                          => 'ONE Suites',
            'Jhamtani Ace Abundance'               => 'Jhamtani Spacebiz',
            'Jhamtani Spacebiz Baner Commercial'   => 'Jhamtani Spacebiz',
            'Jhamtani Spacebiz Baner'              => 'Jhamtani Spacebiz',
            'Azalea/Athena Property'               => 'Azalea Project',
            'Azalea'                               => 'Azalea Project',
            'Godrej Skyline'                       => 'Godrej Skyline',
            'MD Studio'                            => 'MD Studio Apartment',
        ];
        foreach ($map as $keyword => $master) {
            if (stripos($rawName, $keyword) !== false) return $master;
        }
        return $rawName; // return as-is if no match
    }

    public function syncCNPGreenfield(): int {
        // Fetch public CSV directly (no auth needed since sheet is shared)
        $sheetId = defined('SHEET_CNP_GREENFIELD_ID') ? SHEET_CNP_GREENFIELD_ID : '1smfb0vmFW3gaH9gbN7Jsze9cyA-t22ftunL2tX6NaQA';
        $csvUrl  = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv";

        $ch = curl_init($csvUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $csvData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$csvData) {
            throw new RuntimeException("Failed to fetch CNP Greenfield sheet (HTTP {$httpCode}). Make sure the sheet is shared publicly.");
        }

        // Parse CSV
        $lines = explode("\n", $csvData);
        $rows  = [];
        foreach ($lines as $line) {
            $line = trim($line, "\r\n");
            if ($line === '') continue;
            $rows[] = str_getcsv($line);
        }

        // Skip header rows (row 0 = project names, row 1 = column headers)
        if (count($rows) < 3) return 0;

        // Define project sections with their column offsets
        // Each section: [project_name, start_col, name_offset, phone_offset, date_offset, comment_offset, has_call_time]
        $sections = [
            [
                'name'  => 'Elevate Shop',
                'start' => 0,
                'map'   => ['no' => 0, 'budget' => 1, 'site_visit' => 2, 'name' => 3, 'phone' => 4, 'date' => 5, 'comment' => 6],
            ],
            [
                'name'  => 'Athena',
                'start' => 8,
                'map'   => ['no' => 0, 'budget' => 1, 'location' => 2, 'name' => 3, 'phone' => 4, 'date' => 5, 'comment' => 6],
            ],
            [
                'name'  => 'Studio Apartment',
                'start' => 16,
                'map'   => ['no' => 0, 'budget' => 1, 'site_visit' => 2, 'name' => 3, 'phone' => 4, 'date' => 5, 'comment' => 6],
            ],
            [
                'name'  => 'One Holding Kharadi Studio Apartment',
                'start' => 24,
                'map'   => ['no' => 0, 'budget' => 1, 'site_visit' => 2, 'name' => 3, 'phone' => 4, 'date' => 5, 'comment' => 6],
            ],
            [
                'name'  => 'Jhamtani Ace Abundance',
                'start' => 32,
                'map'   => ['no' => 0, 'budget' => 1, 'site_visit' => 2, 'call_time' => 3, 'name' => 4, 'phone' => 5, 'date' => 6, 'comment' => 7],
            ],
            [
                'name'  => 'Jhamtani Spacebiz Baner Commercial',
                'start' => 41,
                'map'   => ['no' => 0, 'budget' => 1, 'site_visit' => 2, 'name' => 3, 'phone' => 4, 'date' => 5, 'comment' => 6],
            ],
            [
                'name'  => 'Azalea/Athena Property',
                'start' => 49,
                'map'   => ['no' => 0, 'budget' => 1, 'site_visit' => 2, 'name' => 3, 'phone' => 4, 'comment' => 5],
            ],
        ];

        $count = 0;
        $pdo   = db();

        // Process data rows (skip first 2 header rows)
        for ($i = 2; $i < count($rows); $i++) {
            $row = $rows[$i];

            foreach ($sections as $sec) {
                $s   = $sec['start'];
                $map = $sec['map'];

                // Get row number — skip if empty or not numeric
                $no = trim($row[$s + $map['no']] ?? '');
                if ($no === '' || !is_numeric($no)) continue;

                // Extract fields based on section mapping
                $budget    = trim($row[$s + $map['budget']] ?? '');
                $siteVisit = trim($row[$s + ($map['site_visit'] ?? $map['location'] ?? 2)] ?? '');
                $name      = trim($row[$s + $map['name']] ?? '');
                $phone     = trim($row[$s + $map['phone']] ?? '');
                $date      = trim($row[$s + ($map['date'] ?? -1)] ?? '');
                $comment   = trim($row[$s + ($map['comment'] ?? -1)] ?? '');
                $callTime  = isset($map['call_time']) ? trim($row[$s + $map['call_time']] ?? '') : '';
                $location  = isset($map['location']) ? trim($row[$s + $map['location']] ?? '') : '';

                // Skip if no name or phone
                if (empty($name) && empty($phone)) continue;

                // Clean phone number: remove "p:", ".+91" prefixes, spaces, dots
                $phone = preg_replace('/^[p.:]+/', '', $phone);
                $phone = preg_replace('/[^0-9+]/', '', $phone);
                if (empty($phone) || strlen($phone) < 10) continue;

                // Normalize to 10-digit Indian number for dedup
                $cleanPhone = $phone;
                if (preg_match('/\+?91(\d{10})$/', $cleanPhone, $m)) {
                    $cleanPhone = $m[1];
                }
                if (strlen($cleanPhone) > 10) {
                    $cleanPhone = substr($cleanPhone, -10);
                }

                // Dedup by mobile across ALL sources (not just meta)
                $dup = $pdo->prepare('SELECT id FROM leads WHERE mobile LIKE ?');
                $dup->execute(['%' . $cleanPhone]);
                if ($dup->fetch()) continue;

                // Map raw section name to official master project name
                $projectName = $this->masterProjectName($sec['name']);
                $projectId = null;
                $ps = $pdo->prepare('SELECT id FROM projects WHERE name=?');
                $ps->execute([$projectName]);
                $proj = $ps->fetch();
                if (!$proj) {
                    $pdo->prepare('INSERT IGNORE INTO projects (name) VALUES (?)')->execute([$projectName]);
                    $projectId = (int)$pdo->lastInsertId();
                } else {
                    $projectId = (int)$proj['id'];
                }

                // Parse date (formats: d/m/yy, d/m/yyyy, dd/mm/yyyy, yyyy-mm-ddThh:mm:ss)
                $createdAt = null;
                if ($date) {
                    // ISO format from some rows
                    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                        $createdAt = date('Y-m-d H:i:s', strtotime($date));
                    }
                    // d/m/yy or d/m/yyyy
                    elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $date, $dm)) {
                        $day   = (int)$dm[1];
                        $month = (int)$dm[2];
                        $year  = (int)$dm[3];
                        if ($year < 100) $year += 2000;
                        $createdAt = sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day);
                    }
                    // d//m/yy (typo in sheet)
                    elseif (preg_match('#^(\d{1,2})//(\d{1,2})/(\d{2,4})$#', $date, $dm)) {
                        $day   = (int)$dm[1];
                        $month = (int)$dm[2];
                        $year  = (int)$dm[3];
                        if ($year < 100) $year += 2000;
                        $createdAt = sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day);
                    }
                }

                // Build notes
                $notes = "Project: {$projectName}\nBudget: {$budget}";
                if ($siteVisit) $notes .= "\nSite Visit: {$siteVisit}";
                if ($location)  $notes .= "\nPreferred Location: {$location}";
                if ($callTime)  $notes .= "\nPreferred Call Time: {$callTime}";
                if ($comment)   $notes .= "\nComment: {$comment}";

                // Insert lead
                // Safeguard: never store a future date
                if ($createdAt && strtotime($createdAt) > time()) {
                    $createdAt = date('Y-m-d H:i:s');
                }

                $sql = 'INSERT INTO leads (source, project_id, project_name, first_name, mobile, preference, notes, sheet_date, lead_type, lead_status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, "warm", "sv_pending", ?)';
                $pdo->prepare($sql)->execute([
                    'meta',
                    $projectId,
                    $projectName,
                    $name,
                    $phone,
                    $budget,
                    $notes,
                    $date,
                    $createdAt ?: date('Y-m-d H:i:s'),
                ]);

                $count++;
            }
        }

        // Log sync
        $pdo->prepare('INSERT INTO sync_log (source, rows_synced) VALUES ("cnp_greenfield", ?)')->execute([$count]);
        return $count;
    }
}

