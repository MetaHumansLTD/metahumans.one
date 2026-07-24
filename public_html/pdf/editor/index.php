<?php
require_once __DIR__ . '/lib.php';
mh_pdf_editor_require_auth();

$userId = (string)($_SESSION['mh_auth_user'] ?? '');
$csrf = mh_pdf_editor_csrf_token();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!mh_pdf_editor_verify_csrf($postedToken)) {
        $error = 'Invalid session';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : 'upload';
        if ($action === 'delete') {
            $id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
            if ($id === '' || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
                $error = 'Invalid id';
            } else {
                try {
                    mh_pdf_editor_delete_record($id, $userId);
                    header('Location: /pdf/editor/');
                    exit;
                } catch (Throwable $e) {
                    $error = 'Delete failed';
                }
            }
        } else {
            if (!isset($_FILES['pdf'])) {
                $error = 'Missing file';
            } else {
                $f = $_FILES['pdf'];
                $name = isset($f['name']) ? (string)$f['name'] : 'document.pdf';
                $tmp = isset($f['tmp_name']) ? (string)$f['tmp_name'] : '';
                $err = isset($f['error']) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
                if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                    $error = 'Upload failed';
                } else {
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)) ?: 'document.pdf';
                    if (strtolower(substr($safe, -4)) !== '.pdf') {
                        $safe .= '.pdf';
                    }
                    $id = mh_pdf_editor_random_id(16);
                    $dest = mh_pdf_editor_files_dir() . '/' . $userId . '_' . $id . '_' . $safe;
                    mh_pdf_editor_ensure_dirs();
                    if (!@move_uploaded_file($tmp, $dest)) {
                        $error = 'Failed to store file';
                    } else {
                        mh_pdf_editor_create_record($userId, $safe, $dest, $id);
                        header('Location: /pdf/editor/open.php?id=' . rawurlencode($id));
                        exit;
                    }
                }
            }
        }
    }
}

$records = mh_pdf_editor_list_records($userId, 50);
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MetaHumans PDF Editor</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
      main.main-content { width: 100%; display: block; }
      .mh-shell { padding: 16px; box-sizing: border-box; max-width: 1100px; margin: 0 auto; }
      .mh-title { margin: 0 0 10px; font-size: 1.2rem; font-weight: 700; color: rgba(226, 232, 240, 0.98); }
      .mh-sub { margin: 0 0 18px; color: rgba(148, 163, 184, 0.98); line-height: 1.45; }
      .mh-card { border: 1px solid rgba(148, 163, 184, 0.18); background: rgba(2, 6, 23, 0.55); border-radius: 14px; padding: 14px; }
      .mh-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
      .mh-input { color: rgba(226, 232, 240, 0.98); }
      .mh-btn { display: inline-block; padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(148, 163, 184, 0.24); background: rgba(2, 6, 23, 0.75); color: rgba(226, 232, 240, 0.98); text-decoration: none; cursor: pointer; }
      .mh-btn:hover { background: rgba(2, 6, 23, 0.9); }
      .mh-btn-danger { border-color: rgba(248, 113, 113, 0.3); background: rgba(127, 29, 29, 0.35); }
      .mh-btn-danger:hover { background: rgba(127, 29, 29, 0.55); }
      .mh-list { margin-top: 14px; display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
      .mh-item { padding: 12px 12px; border-radius: 14px; border: 1px solid rgba(148, 163, 184, 0.18); background: rgba(2, 6, 23, 0.55); }
      .mh-item-title { font-weight: 650; color: rgba(226, 232, 240, 0.98); margin: 0 0 8px; }
      .mh-item-meta { font-size: 0.9rem; color: rgba(148, 163, 184, 0.98); margin: 0 0 10px; }
      .mh-item-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
      .mh-form-inline { display: inline; }
      .mh-error { margin: 0 0 14px; color: rgba(248, 113, 113, 0.98); }
      @media (max-width: 640px) { .mh-shell { padding: 10px; } }
    </style>
  </head>
  <body>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
      <div class="mh-shell">
        <h1 class="mh-title">PDF Editor</h1>
        <p class="mh-sub">Upload a PDF and edit text/images using the in-browser editor. Save exports back to PDF.</p>

        <?php if ($error !== ''): ?>
          <div class="mh-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="mh-card">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
            <div class="mh-row">
              <input class="mh-input" type="file" name="pdf" accept="application/pdf,.pdf" required />
              <button class="mh-btn" type="submit">Upload & Open</button>
            </div>
          </form>
        </div>

        <?php if (!empty($records)): ?>
          <div class="mh-list">
            <?php foreach ($records as $r): ?>
              <div class="mh-item">
                <div class="mh-item-title"><?php echo htmlspecialchars((string)$r['filename']); ?></div>
                <div class="mh-item-meta">Version <?php echo (int)$r['version']; ?> · Updated <?php echo gmdate('Y-m-d H:i', (int)$r['updated_at']); ?> UTC</div>
                <div class="mh-item-actions">
                  <a class="mh-btn" href="/pdf/editor/open.php?id=<?php echo rawurlencode((string)$r['id']); ?>">Open</a>
                  <form class="mh-form-inline" method="post">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>" />
                    <input type="hidden" name="action" value="delete" />
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$r['id']); ?>" />
                    <button class="mh-btn mh-btn-danger" type="submit" onclick="return confirm('Delete this file? This cannot be undone.');">Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </main>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
  </body>
</html>
