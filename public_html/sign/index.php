<?php
header('Location: /pdf-tools/digital-sign-pdf.html', true, 302);
exit;
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MetaHumans PDF Signing</title>
    <?php if (false && function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
      main.main-content {
        width: 100%;
        display: block;
      }
      .mh-app-frame {
        width: 100%;
        height: 100%;
        min-height: 720px;
        border: 0;
        border-radius: 14px;
        overflow: hidden;
        background: rgba(2, 6, 23, 0.35);
      }
      .mh-app-shell {
        padding: 16px;
        box-sizing: border-box;
      }
      .mh-loading {
        position: absolute;
        inset: 16px;
        border-radius: 14px;
        background: rgba(2, 6, 23, 0.55);
        border: 1px solid rgba(148, 163, 184, 0.18);
        display: flex;
        align-items: center;
        justify-content: center;
        color: rgba(226, 232, 240, 0.92);
        font-weight: 600;
        letter-spacing: 0.2px;
        pointer-events: none;
      }
      .mh-frame-wrap {
        position: relative;
      }
      @media (max-width: 640px) {
        .mh-app-shell {
          padding: 10px;
        }
        .mh-loading {
          inset: 10px;
        }
      }
    </style>
  </head>
  <body>
    <?php if (false && function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>

    <main class="main-content">
      <div class="mh-app-shell">
        <div class="mh-frame-wrap">
          <div class="mh-loading" id="mhLoading">Loading signing tools…</div>
          <iframe
            class="mh-app-frame"
            id="mhAppFrame"
            title="MetaHumans PDF Signing"
            src="https://sign.metahumans.one/"
            allow="clipboard-read; clipboard-write"
          ></iframe>
        </div>
      </div>
    </main>

    <script>
      (function () {
        var frame = document.getElementById("mhAppFrame");
        var loading = document.getElementById("mhLoading");
        if (frame && loading) {
          frame.addEventListener("load", function () {
            loading.style.display = "none";
          });
        }
      })();
    </script>

    <?php if ($cueLoaded && function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
  </body>
</html>
