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
}
