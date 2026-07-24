<?php
// Meta Humans - Black Logo Animated (PHP Version)
// Dynamic asset path configuration
$asset_base = '/assets/images/branding';
$black_logo = $asset_base . '/logo/logo-black.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans - Black Logo Animated</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Arial', sans-serif;
            overflow: hidden;
        }

        .logo-container {
            position: relative;
            text-align: center;
            padding: 50px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Meta Humans Black Logo Animation Styles */
        @keyframes logoCombinedGlow {
            0%, 100% {
                filter: drop-shadow(-10px -5px 15px rgba(245, 158, 11, 0.7)) 
                        drop-shadow(10px 5px 15px rgba(13, 71, 161, 0.7))
                        drop-shadow(-20px -10px 30px rgba(245, 158, 11, 0.4))
                        drop-shadow(20px 10px 30px rgba(13, 71, 161, 0.4))
                        drop-shadow(0 0 10px rgba(0, 0, 0, 0.3));
            }
            25% {
                filter: drop-shadow(-15px -8px 25px rgba(245, 158, 11, 0.9)) 
                        drop-shadow(8px 3px 10px rgba(13, 71, 161, 0.5))
                        drop-shadow(-30px -15px 50px rgba(245, 158, 11, 0.6))
                        drop-shadow(15px 8px 20px rgba(13, 71, 161, 0.2))
                        drop-shadow(0 0 15px rgba(0, 0, 0, 0.4));
            }
            50% {
                filter: drop-shadow(-8px -3px 10px rgba(245, 158, 11, 0.5)) 
                        drop-shadow(15px 8px 25px rgba(13, 71, 161, 0.9))
                        drop-shadow(-15px -8px 20px rgba(245, 158, 11, 0.2))
                        drop-shadow(30px 15px 50px rgba(13, 71, 161, 0.6))
                        drop-shadow(0 0 12px rgba(0, 0, 0, 0.5));
            }
            75% {
                filter: drop-shadow(-12px -6px 18px rgba(245, 158, 11, 0.8)) 
                        drop-shadow(12px 6px 18px rgba(13, 71, 161, 0.8))
                        drop-shadow(-25px -12px 35px rgba(245, 158, 11, 0.5))
                        drop-shadow(25px 12px 35px rgba(13, 71, 161, 0.5))
                        drop-shadow(0 0 8px rgba(0, 0, 0, 0.3));
            }
        }

        @keyframes logoFloat {
            0%, 100% {
                transform: translateY(0px) scale(1);
            }
            25% {
                transform: translateY(-10px) scale(1.015);
            }
            50% {
                transform: translateY(-15px) scale(1.03);
            }
            75% {
                transform: translateY(-10px) scale(1.015);
            }
        }

        .meta-humans-black-logo {
            position: relative;
            display: inline-block;
            animation: logoFloat 7s ease-in-out infinite;
            margin: 30px 0;
            perspective: 1200px;
            cursor: pointer;
            transition: all 0.4s ease;
        }

        .meta-humans-black-logo:hover {
            transform: scale(1.05);
            filter: drop-shadow(-15px -8px 20px rgba(245, 158, 11, 0.9)) 
                    drop-shadow(15px 8px 20px rgba(13, 71, 161, 0.9))
                    drop-shadow(-30px -15px 40px rgba(245, 158, 11, 0.6))
                    drop-shadow(30px 15px 40px rgba(13, 71, 161, 0.6))
                    drop-shadow(0 0 20px rgba(0, 0, 0, 0.5));
        }

        .black-logo-image {
            width: 400px;
            height: auto;
            max-width: 100%;
            transition: all 0.4s ease;
            animation: logoCombinedGlow 5s ease-in-out infinite;
            transform-origin: center;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            backdrop-filter: blur(10px);
        }

        .meta-humans-black-logo:hover .black-logo-image {
            transform: scale(1.05);
            filter: drop-shadow(-15px -8px 20px rgba(245, 158, 11, 0.9)) 
                    drop-shadow(15px 8px 20px rgba(13, 71, 161, 0.9))
                    drop-shadow(-30px -15px 40px rgba(245, 158, 11, 0.6))
                    drop-shadow(30px 15px 40px rgba(13, 71, 161, 0.6))
                    drop-shadow(0 0 20px rgba(0, 0, 0, 0.5)) !important;
            background: rgba(255, 255, 255, 0.2);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .black-logo-image {
                width: 300px;
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .black-logo-image {
                width: 250px;
                padding: 10px;
            }
        }

        /* Light theme specific effects */
        .meta-humans-black-logo::before {
            content: '';
            position: absolute;
            top: -20px;
            left: -20px;
            right: -20px;
            bottom: -20px;
            background: linear-gradient(45deg, 
                rgba(245, 158, 11, 0.1), 
                rgba(13, 71, 161, 0.1), 
                rgba(245, 158, 11, 0.1));
            border-radius: 30px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .meta-humans-black-logo:hover::before {
            opacity: 1;
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <!-- Animated Meta Humans Black Logo -->
        <div class="meta-humans-black-logo">
            <img src="<?php echo $black_logo; ?>" 
                 alt="Meta Humans Black Logo" 
                 class="black-logo-image">
        </div>
    </div>
</body>
</html>
