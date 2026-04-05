<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');
$pageTitle = 'Import Offline Leads';

$pdo = db();
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['csv_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        
        if ($ext === 'csv') {
            if (($handle = fopen($tmpName, "r")) !== FALSE) {
                // Get headers
                $header = fgetcsv($handle, 1000, ",");
                if ($header) {
                    $header = array_map('strtolower', $header);
                    $header = array_map('trim', $header);

                    $count = 0;
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (count($data) == 1 && $data[0] == null) continue; // Skip empty rows

                        $row = array_combine($header, array_pad($data, count($header), ''));
                        
                        // Map fields - trying common variations
                        $first = trim($row['first name'] ?? $row['first_name'] ?? $row['name'] ?? '');
                        $last  = trim($row['last name'] ?? $row['last_name'] ?? '');
                        $mob   = trim($row['mobile'] ?? $row['phone'] ?? $row['contact'] ?? '');
                        $eml   = trim($row['email'] ?? $row['email address'] ?? '');
                        $pref  = trim($row['preference'] ?? $row['budget'] ?? $row['requirements'] ?? '');
                        $pname = trim($row['project'] ?? $row['project name'] ?? '');

                        if ($mob) {
                            $pid = null;
                            if ($pname) {
                                $s = $pdo->prepare('SELECT id FROM projects WHERE name=?');
                                $s->execute([$pname]);
                                $pr = $s->fetch();
                                if (!$pr) {
                                    $pdo->prepare('INSERT INTO projects (name) VALUES (?)')->execute([$pname]);
                                    $pid = (int)$pdo->lastInsertId();
                                } else {
                                    $pid = (int)$pr['id'];
                                }
                            }

                            $pdo->prepare('INSERT INTO leads
                                (source,project_id,first_name,last_name,mobile,email,preference)
                                VALUES (?,?,?,?,?,?,?)')
                            ->execute(['manual', $pid, $first, $last, $mob, $eml, $pref]);
                            $count++;
                        }
                    }
                    $success = "Successfully imported $count leads.";
                } else {
                    $error = "The CSV file is empty or missing headers.";
                }
                fclose($handle);
            } else {
                $error = "Failed to read the CSV file.";
            }
        } else {
            $error = "Only CSV files are allowed.";
        }
    } else {
        $error = "File upload error.";
    }
}

include __DIR__ . '/includes/header.php';
?>

<div style="max-width:800px; margin: 0 auto;">
  <div style="margin-bottom:24px;">
    <h2 style="font-family:'DM Serif Display',serif;font-size:24px;">📥 Import Offline Leads</h2>
    <p style="color:var(--text2);font-size:14px;margin-top:6px;">Upload a CSV file exported from Excel or Google Sheets to automatically import leads into the CRM.</p>
  </div>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:16px; margin-bottom: 15px;">CSV Layout Format</h3>
    <p style="color:var(--text2);font-size:13px;margin-bottom:15px">Make sure your CSV has a header row. We auto-detect the following column names (case-insensitive):</p>
    <ul style="color:var(--text);font-size:14px;margin-bottom:20px;padding-left:20px;line-height:1.6">
        <li><strong>First Name</strong> (or Name)</li>
        <li><strong>Last Name</strong></li>
        <li><strong>Mobile</strong> (or Phone, Contact) — <em>Required</em></li>
        <li><strong>Email</strong></li>
        <li><strong>Project</strong></li>
        <li><strong>Preference</strong> (or Budget, Requirements)</li>
    </ul>

    <form method="POST" enctype="multipart/form-data" style="background:var(--bg);padding:24px;border-radius:12px;border:1px dashed rgba(255,255,255,0.2);text-align:center">
      <div style="margin-bottom: 20px;">
        <input type="file" name="csv_file" accept=".csv" required style="max-width:300px;margin:0 auto;display:block">
      </div>
      <button type="submit" class="btn btn-primary">⤒ Upload & Import CSV</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
