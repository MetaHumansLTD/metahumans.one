<?php
// CUE framework include
require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';

function includeAutosaveWidget(array $options = []): void {
    $interval = isset($options['interval']) ? (int)$options['interval'] : 5000;
    if ($interval < 1000) { $interval = 1000; }
    $eventName = isset($options['eventName']) ? (string)$options['eventName'] : 'autosave:tick';
    ob_start();
    ?>
    <script>
        (function(){
            const evtName = <?php echo json_encode($eventName); ?>;
            setInterval(()=>{
                const ev = new CustomEvent(evtName, { detail: { ts: Date.now() } });
                document.dispatchEvent(ev);
            }, <?php echo (int)$interval; ?>);
        })();
    </script>
    <?php
    echo ob_get_clean();
}

?>

