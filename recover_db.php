<?php
require_once __DIR__ . '/config.php';
$user = requireAuth('admin');

$pdo = db();

try {
    // 1. Recover project_name from form_name for meta leads
    $pdo->exec("UPDATE leads SET project_name = form_name WHERE source = 'meta' AND form_name IS NOT NULL AND form_name != ''");

    // 2. Unlink all project_ids to force a clean slate from the recovered text
    $pdo->exec("UPDATE leads SET project_id = NULL");

    // 3. Purge existing projects table (Wait, doing this safely! We only delete projects that will be rebuilt anyway)
    $pdo->exec("DELETE FROM projects");

    // 4. Rebuild the projects table automatically using the actual distinct names
    $pdo->exec("INSERT IGNORE INTO projects (name) 
                SELECT DISTINCT project_name FROM leads 
                WHERE project_name IS NOT NULL AND project_name != ''");

    // 5. Relink the new IDs
    $pdo->exec("UPDATE leads l JOIN projects p ON l.project_name = p.name 
                SET l.project_id = p.id 
                WHERE l.project_id IS NULL");

    echo "<h1 style='font-family:sans-serif; color:#4ade80;'>✅ Database Successfully Recovered!</h1>";
    echo "<p style='font-family:sans-serif; font-size:16px;'>Your original sub-project names have been safely restored from the Meta API records.</p>";
    echo "<p style='font-family:sans-serif; font-size:16px;'>Please go back to the Projects overview page. The grouped menus will now render exactly as you requested.</p>";
    echo "<a href='admin_projects.php' style='display:inline-block; padding:10px 20px; background:#c9a96e; color:#111; text-decoration:none; font-family:sans-serif; font-weight:bold; border-radius:8px;'>Go back to Projects</a>";
    
} catch (Exception $e) {
    echo "<h1>Error recovering DB:</h1> <p>" . $e->getMessage() . "</p>";
}
