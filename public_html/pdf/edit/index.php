<?php
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';

$tools = [
    ['label' => 'Edit pages (reorder/delete/extract)', 'href' => '/pdf/?tool=organize-pdf.html'],
    ['label' => 'Crop', 'href' => '/pdf/?tool=crop-pdf.html'],
    ['label' => 'Rotate', 'href' => '/pdf/?tool=rotate-pdf.html'],
    ['label' => 'Watermark', 'href' => '/pdf/?tool=add-watermark.html'],
    ['label' => 'Stamps', 'href' => '/pdf/?tool=add-stamps.html'],
    ['label' => 'Adjust colors', 'href' => '/pdf/?tool=adjust-colors.html'],
    ['label' => 'Text color', 'href' => '/pdf/?tool=text-color.html'],
    ['label' => 'Background color', 'href' => '/pdf/?tool=background-color.html'],
    ['label' => 'Remove annotations', 'href' => '/pdf/?tool=remove-annotations.html'],
    ['label' => 'Metadata editor', 'href' => '/pdf/?tool=edit-metadata.html'],
    ['label' => 'Remove metadata', 'href' => '/pdf/?tool=remove-metadata.html'],
    ['label' => 'Create fillable form fields', 'href' => '/pdf/?tool=form-creator.html'],
    ['label' => 'Sign / add text / strike-through', 'href' => '/sign/'],
];
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MetaHumans PDF Editor</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
      main.main-content {
        width: 100%;
        display: block;
      }
      .mh-shell {
        padding: 16px;
        box-sizing: border-box;
        max-width: 1100px;
        margin: 0 auto;
      }
      .mh-title {
        margin: 0 0 10px;
        font-size: 1.2rem;
        font-weight: 700;
        color: rgba(226, 232, 240, 0.98);
      }
      .mh-sub {
        margin: 0 0 18px;
        color: rgba(148, 163, 184, 0.98);
        line-height: 1.45;
      }
      .mh-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 12px;
      }
      .mh-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 14px 14px;
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(2, 6, 23, 0.55);
        text-decoration: none;
        color: rgba(226, 232, 240, 0.98);
      }
      .mh-card:hover {
        background: rgba(2, 6, 23, 0.68);
      }
      .mh-card span {
        font-weight: 650;
      }
      .mh-pill {
        font-size: 0.82rem;
        color: rgba(148, 163, 184, 0.95);
      }
      @media (max-width: 640px) {
        .mh-shell {
          padding: 10px;
        }
      }
    </style>
  </head>
  <body>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>

    <main class="main-content">
      <div class="mh-shell">
        <h1 class="mh-title">PDF Edit</h1>
        <p class="mh-sub">
          These tools support common PDF editing operations (pages, layout, watermarks, stamps, metadata, form fields).
          For editing existing text/images in-place, use the signing suite for overlays (text, stamps, strike-through).
        </p>

        <div class="mh-grid">
          <?php foreach ($tools as $tool): ?>
            <a class="mh-card" href="<?php echo htmlspecialchars($tool['href']); ?>">
              <span><?php echo htmlspecialchars($tool['label']); ?></span>
              <div class="mh-pill">Open</div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </main>

    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
  </body>
</html>

