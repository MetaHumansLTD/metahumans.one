<?php
// Video Dashboard
require_once '../cue.php';
require_once '../auth/header-integration.php';

// Authentication and permissions (disabled for testing)
// requireAuth();
// requirePermission('videos', 'view');

// Page configuration
$page_title = 'Video Dashboard';
$page_description = 'Manage and view your generated videos';

// Simple functions for dashboard functionality
if (!function_exists('getCurrentUser')) {
    function getCurrentUser() {
        return [
            'id' => 'test_user',
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];
    }
}

if (!function_exists('getSystemStatus')) {
    function getSystemStatus() {
        return [
            'status' => 'operational',
            'uptime' => '99.9%',
            'last_updated' => date('Y-m-d H:i:s')
        ];
    }
}

// Get current user and system status
$current_user = getCurrentUser();
$system_status = getSystemStatus();

// Get user's videos from API
$user_videos = [];
try {
    // Make internal API call to get videos
    $api_url = 'http://localhost:8080/ctrl/metahumans/api/video-api.php';
    $post_data = http_build_query(['action' => 'get_videos']);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $post_data
        ]
    ]);
    
    $response = file_get_contents($api_url, false, $context);
    if ($response) {
        $api_result = json_decode($response, true);
        if ($api_result && $api_result['success'] && isset($api_result['videos'])) {
            foreach ($api_result['videos'] as $video) {
                $user_videos[] = [
                    'id' => $video['id'],
                    'title' => $video['title'],
                    'status' => $video['status'],
                    'created_at' => $video['created_at'],
                    'duration' => isset($video['duration']) ? $video['duration'] . ':00' : '0:00',
                    'thumbnail' => '/templates/assets/images/video-placeholder.png',
                    'video_url' => $video['status'] === 'completed' ? ($video['video_path'] ?? '/clienthub/demo-video.mp4') : null,
                    'type' => $video['scenario'] ?? 'unknown',
                    'error_message' => $video['error_message'] ?? null,
                    'completed_at' => $video['completed_at'] ?? null,
                    'failed_at' => $video['failed_at'] ?? null
                ];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error loading videos: " . $e->getMessage());
}

// Add some demo videos if no real videos exist
if (empty($user_videos)) {
    $user_videos = [
        [
            'id' => 'demo_1',
            'title' => 'Welcome Message',
            'status' => 'completed',
            'created_at' => '2024-01-15',
            'duration' => '0:45',
            'thumbnail' => '/templates/assets/images/video-placeholder.png',
            'video_url' => '/clienthub/demo-video.mp4',
            'type' => 'demo'
        ]
    ];
}

// Load header template
require_once '../templates/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="<?php echo $page_description; ?>">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo getAssetPath('favicon.ico'); ?>">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo getAssetPath('css/header.css'); ?>">
    <link rel="stylesheet" href="<?php echo getAssetPath('css/footer.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .dashboard-header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
        }
        
        .dashboard-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .dashboard-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .videos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .video-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            color: white;
            transition: transform 0.3s ease;
        }
        
        .video-card:hover {
            transform: translateY(-5px);
        }
        
        .video-thumbnail {
            width: 100%;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: rgba(255, 255, 255, 0.5);
            position: relative;
        }
        
        .play-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.7);
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        .video-info h3 {
            margin: 0 0 10px 0;
            font-size: 1.3rem;
        }
        
        .video-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .video-status {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .status-completed {
            background: rgba(76, 175, 80, 0.3);
            color: #4CAF50;
        }
        
        .status-processing {
            background: rgba(255, 193, 7, 0.3);
            color: #FFC107;
        }
        
        .status-failed {
            background: rgba(244, 67, 54, 0.3);
            color: #F44336;
        }
        
        .video-duration {
            background: rgba(0, 0, 0, 0.5);
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.9rem;
        }
        
        .completion-info {
            color: #4CAF50;
            font-size: 0.9rem;
            margin: 5px 0;
        }
        
        .error-info {
            color: #F44336;
            font-size: 0.9rem;
            margin: 5px 0;
        }
        
        .error-message {
            color: #FF9800;
            font-size: 0.85rem;
            font-style: italic;
            margin: 5px 0;
            background: rgba(255, 152, 0, 0.1);
            padding: 5px 8px;
            border-radius: 5px;
        }
        
        .video-actions {
            margin-top: 15px;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 20px;
            margin-right: 10px;
            margin-bottom: 5px;
            transition: background 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .btn-primary {
            background: rgba(103, 58, 183, 0.8);
        }
        
        .btn-primary:hover {
            background: rgba(103, 58, 183, 1);
        }
        
        .btn-danger {
            background: rgba(244, 67, 54, 0.8);
        }
        
        .btn-danger:hover {
            background: rgba(244, 67, 54, 1);
        }
        
        .create-new-section {
            text-align: center;
            margin-top: 40px;
        }
        
        .btn-create-new {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(45deg, #FF6B6B, #4ECDC4);
            color: white;
            text-decoration: none;
            border-radius: 30px;
            font-size: 1.1rem;
            font-weight: bold;
            transition: transform 0.3s ease;
        }
        
        .btn-create-new:hover {
            transform: scale(1.05);
        }
        
        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            color: white;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1><i class="fas fa-video"></i> Video Dashboard</h1>
            <p>Manage and view your generated videos</p>
        </div>
        
        <div class="stats-section">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($user_videos); ?></div>
                <div class="stat-label">Total Videos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_filter($user_videos, fn($v) => $v['status'] === 'completed')); ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_filter($user_videos, fn($v) => $v['status'] === 'processing')); ?></div>
                <div class="stat-label">Processing</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_filter($user_videos, fn($v) => $v['status'] === 'failed')); ?></div>
                <div class="stat-label">Failed</div>
            </div>
        </div>
        
        <div class="videos-grid">
            <?php foreach ($user_videos as $video): ?>
            <div class="video-card" data-video-id="<?php echo htmlspecialchars($video['id']); ?>">
                <div class="video-thumbnail">
                    <i class="fas fa-film"></i>
                    <?php if ($video['status'] === 'completed'): ?>
                    <div class="play-overlay">
                        <i class="fas fa-play"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="video-info">
                    <h3><?php echo htmlspecialchars($video['title']); ?></h3>
                    <div class="video-meta">
                        <span class="video-status status-<?php echo $video['status']; ?>">
                            <?php echo ucfirst($video['status']); ?>
                        </span>
                        <span class="video-duration"><?php echo $video['duration']; ?></span>
                    </div>
                    <p>Created: <?php echo $video['created_at']; ?></p>
                    <?php if ($video['status'] === 'completed' && !empty($video['completed_at'])): ?>
                        <p class="completion-info">Completed: <?php echo $video['completed_at']; ?></p>
                    <?php elseif ($video['status'] === 'failed' && !empty($video['failed_at'])): ?>
                        <p class="error-info">Failed: <?php echo $video['failed_at']; ?></p>
                        <?php if (!empty($video['error_message'])): ?>
                            <p class="error-message"><?php echo htmlspecialchars($video['error_message']); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="video-actions">
                    <?php if ($video['status'] === 'completed'): ?>
                        <button class="btn btn-primary" onclick="playVideo('<?php echo htmlspecialchars($video['video_url'] ?? ''); ?>', '<?php echo htmlspecialchars($video['title']); ?>')">
                            <i class="fas fa-play"></i> Watch
                        </button>
                        <a href="<?php echo htmlspecialchars($video['video_url'] ?? ''); ?>" download class="btn">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <a href="video-creator.php?edit=<?php echo htmlspecialchars($video['id'] ?? ''); ?>" class="btn">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="#" class="btn">
                            <i class="fas fa-share"></i> Share
                        </a>
                        <button class="btn btn-danger" onclick="confirmDeleteVideo('<?php echo htmlspecialchars($video['id'] ?? ''); ?>', '<?php echo htmlspecialchars($video['title']); ?>')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    <?php elseif ($video['status'] === 'processing'): ?>
                        <a href="#" class="btn">
                            <i class="fas fa-clock"></i> Processing...
                        </a>
                        <button class="btn btn-danger" onclick="confirmDeleteVideo('<?php echo htmlspecialchars($video['id'] ?? ''); ?>', '<?php echo htmlspecialchars($video['title']); ?>')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    <?php else: ?>
                        <a href="#" class="btn btn-danger">
                            <i class="fas fa-exclamation-triangle"></i> Retry
                        </a>
                        <a href="#" class="btn">
                            <i class="fas fa-info-circle"></i> Details
                        </a>
                        <button class="btn btn-danger" onclick="confirmDeleteVideo('<?php echo htmlspecialchars($video['id'] ?? ''); ?>', '<?php echo htmlspecialchars($video['title']); ?>')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="create-new-section">
            <a href="video-creator.php" class="btn-create-new">
                <i class="fas fa-plus"></i> Create New Video
            </a>
        </div>
    </div>

    <!-- Video Player Modal -->
    <div id="videoModal" class="video-modal" style="display: none;">
        <div class="video-modal-content">
            <div class="video-modal-header">
                <h3 id="videoTitle">Video Player</h3>
                <button class="video-modal-close" onclick="closeVideoModal()">&times;</button>
            </div>
            <div class="video-modal-body">
                <video id="videoPlayer" controls style="width: 100%; max-height: 70vh;">
                    <source id="videoSource" src="" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
        </div>
    </div>

    <style>
    .video-modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .video-modal-content {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        border-radius: 15px;
        padding: 20px;
        max-width: 90%;
        max-height: 90%;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
    }

    .video-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        color: white;
    }

    .video-modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        padding: 0;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .video-modal-close:hover {
        color: #ff6b6b;
    }
    </style>

    <script>
    function playVideo(videoUrl, title) {
        if (!videoUrl) {
            alert('Video not available');
            return;
        }
        
        const modal = document.getElementById('videoModal');
        const videoPlayer = document.getElementById('videoPlayer');
        const videoSource = document.getElementById('videoSource');
        const videoTitle = document.getElementById('videoTitle');
        
        videoTitle.textContent = title;
        videoSource.src = videoUrl;
        videoPlayer.load();
        modal.style.display = 'flex';
        
        // Play video after modal is shown
        setTimeout(() => {
            videoPlayer.play().catch(e => {
                console.log('Auto-play prevented:', e);
            });
        }, 100);
    }

    function closeVideoModal() {
        const modal = document.getElementById('videoModal');
        const videoPlayer = document.getElementById('videoPlayer');
        
        videoPlayer.pause();
        videoPlayer.currentTime = 0;
        modal.style.display = 'none';
    }

    // Close modal when clicking outside
    document.getElementById('videoModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeVideoModal();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeVideoModal();
            closeDeleteModal();
        }
    });

    // Delete video functions
    function confirmDeleteVideo(videoId, videoTitle) {
        const modal = document.getElementById('deleteModal');
        const titleElement = document.getElementById('deleteVideoTitle');
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        
        titleElement.textContent = videoTitle;
        confirmBtn.onclick = function() {
            deleteVideo(videoId);
        };
        
        modal.style.display = 'block';
    }

    function deleteVideo(videoId) {
        // Show loading state
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        const originalText = confirmBtn.innerHTML;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        confirmBtn.disabled = true;

        // Simulate API call (replace with actual API endpoint)
        fetch('/ctrl/metahumans/api/video-api.php', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'delete_video',
                video_id: videoId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the video card from the DOM
                const videoCard = document.querySelector(`[data-video-id="${videoId}"]`);
                if (videoCard) {
                    videoCard.remove();
                }
                closeDeleteModal();
                showNotification('Video deleted successfully', 'success');
            } else {
                showNotification('Failed to delete video: ' + (data.message || 'Unknown error'), 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Failed to delete video. Please try again.', 'error');
        })
        .finally(() => {
            // Reset button state
            confirmBtn.innerHTML = originalText;
            confirmBtn.disabled = false;
        });
    }

    function closeDeleteModal() {
        const modal = document.getElementById('deleteModal');
        modal.style.display = 'none';
    }

    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            ${message}
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
    </script>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="video-modal" style="display: none;">
        <div class="video-modal-content delete-modal-content">
            <span class="video-modal-close" onclick="closeDeleteModal()">&times;</span>
            <div class="delete-modal-header">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Confirm Delete</h3>
            </div>
            <div class="delete-modal-body">
                <p>Are you sure you want to delete the video:</p>
                <p class="video-title-highlight" id="deleteVideoTitle"></p>
                <p class="warning-text">This action cannot be undone.</p>
            </div>
            <div class="delete-modal-actions">
                <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash"></i> Delete Video
                </button>
            </div>
        </div>
    </div>

    <style>
        .delete-modal-content {
            max-width: 500px;
            text-align: center;
        }

        .delete-modal-header {
            margin-bottom: 20px;
        }

        .delete-modal-header i {
            font-size: 48px;
            color: #f44336;
            margin-bottom: 10px;
        }

        .delete-modal-header h3 {
            color: white;
            margin: 0;
        }

        .delete-modal-body {
            margin-bottom: 30px;
            color: white;
        }

        .video-title-highlight {
            font-weight: bold;
            color: #ffd700;
            font-size: 1.1em;
            margin: 10px 0;
        }

        .warning-text {
            color: #ff9800;
            font-style: italic;
            font-size: 0.9em;
        }

        .delete-modal-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn-secondary {
            background: rgba(108, 117, 125, 0.8);
        }

        .btn-secondary:hover {
            background: rgba(108, 117, 125, 1);
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            font-weight: bold;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease-out;
        }

        .notification-success {
            background: #4caf50;
        }

        .notification-error {
            background: #f44336;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</body>
</html>