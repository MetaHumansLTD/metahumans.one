<?php
// Meta Humans - Alternative Triangle Logo (PHP Version)
// Dynamic asset path configuration
$asset_base = '/templates/assets/images/branding';
$triangle_logo = $asset_base . '/triangle/logo-triangle-1000.png';
$created_date = date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans - Alternative Triangle Logo</title>
    <style>
        body {
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 50%, #16213e 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: 'Rajdhani', sans-serif;
        }
        
        .logo-container {
            text-align: center;
            padding: 50px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 25px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        /* Meta Humans Logo Animation Styles */
        @keyframes logoTriangleGlow {
            0%, 100% {
                filter: drop-shadow(0 0 20px rgba(245, 158, 11, 0.8)) 
                        drop-shadow(0 0 40px rgba(245, 158, 11, 0.6))
                        drop-shadow(0 0 60px rgba(245, 158, 11, 0.4))
                        drop-shadow(0 0 15px rgba(13, 71, 161, 0.8))
                        drop-shadow(0 0 30px rgba(13, 71, 161, 0.6))
                        drop-shadow(0 0 45px rgba(13, 71, 161, 0.4));
            }
            25% {
                filter: drop-shadow(0 0 25px rgba(245, 158, 11, 1)) 
                        drop-shadow(0 0 50px rgba(245, 158, 11, 0.8))
                        drop-shadow(0 0 75px rgba(245, 158, 11, 0.6))
                        drop-shadow(0 0 10px rgba(13, 71, 161, 0.6))
                        drop-shadow(0 0 20px rgba(13, 71, 161, 0.4))
                        drop-shadow(0 0 30px rgba(13, 71, 161, 0.2));
            }
            50% {
                filter: drop-shadow(0 0 15px rgba(245, 158, 11, 0.6)) 
                        drop-shadow(0 0 30px rgba(245, 158, 11, 0.4))
                        drop-shadow(0 0 45px rgba(245, 158, 11, 0.2))
                        drop-shadow(0 0 25px rgba(13, 71, 161, 1))
                        drop-shadow(0 0 50px rgba(13, 71, 161, 0.8))
                        drop-shadow(0 0 75px rgba(13, 71, 161, 0.6));
            }
            75% {
                filter: drop-shadow(0 0 22px rgba(245, 158, 11, 0.9)) 
                        drop-shadow(0 0 44px rgba(245, 158, 11, 0.7))
                        drop-shadow(0 0 66px rgba(245, 158, 11, 0.5))
                        drop-shadow(0 0 22px rgba(13, 71, 161, 0.9))
                        drop-shadow(0 0 44px rgba(13, 71, 161, 0.7))
                        drop-shadow(0 0 66px rgba(13, 71, 161, 0.5));
            }
        }

        @keyframes logoFloat {
            0%, 100% {
                transform: translateY(0px) scale(1) rotateZ(0deg);
            }
            25% {
                transform: translateY(-8px) scale(1.02) rotateZ(1deg);
            }
            50% {
                transform: translateY(-12px) scale(1.03) rotateZ(0deg);
            }
            75% {
                transform: translateY(-8px) scale(1.02) rotateZ(-1deg);
            }
        }

        @keyframes logoRotate3D {
            0% {
                transform: rotateY(0deg) rotateX(0deg) rotateZ(0deg);
            }
            25% {
                transform: rotateY(90deg) rotateX(10deg) rotateZ(5deg);
            }
            50% {
                transform: rotateY(180deg) rotateX(0deg) rotateZ(0deg);
            }
            75% {
                transform: rotateY(270deg) rotateX(-10deg) rotateZ(-5deg);
            }
            100% {
                transform: rotateY(360deg) rotateX(0deg) rotateZ(0deg);
            }
        }

        @keyframes logoPulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.08);
                opacity: 0.95;
            }
        }

        .meta-humans-logo {
            position: relative;
            display: inline-block;
            animation: logoFloat 8s ease-in-out infinite;
            margin: 30px 0;
            perspective: 1000px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .meta-humans-logo:hover {
            animation: logoRotate3D 4s ease-in-out;
            transform: scale(1.1);
        }

        .meta-humans-logo:active {
            animation: logoPulse 0.6s ease-in-out;
        }

        .logo-image {
            width: 400px;
            height: auto;
            max-width: 100%;
            transition: all 0.4s ease;
            animation: logoTriangleGlow 6s ease-in-out infinite;
            transform-origin: center;
            border-radius: 15px;
            background: linear-gradient(135deg, 
                rgba(245, 158, 11, 0.05), 
                rgba(13, 71, 161, 0.05), 
                rgba(245, 158, 11, 0.05));
            padding: 20px;
            backdrop-filter: blur(10px);
        }

        .meta-humans-logo:hover .logo-image {
            transform: scale(1.08);
            filter: drop-shadow(0 0 30px rgba(245, 158, 11, 1)) 
                    drop-shadow(0 0 60px rgba(245, 158, 11, 0.8))
                    drop-shadow(0 0 90px rgba(245, 158, 11, 0.6))
                    drop-shadow(0 0 25px rgba(13, 71, 161, 1))
                    drop-shadow(0 0 50px rgba(13, 71, 161, 0.8))
                    drop-shadow(0 0 75px rgba(13, 71, 161, 0.6))
                    drop-shadow(0 0 50px rgba(255, 255, 255, 0.4)) !important;
            background: linear-gradient(135deg, 
                rgba(245, 158, 11, 0.15), 
                rgba(13, 71, 161, 0.15), 
                rgba(245, 158, 11, 0.15));
        }

        .company-text {
            color: white;
            font-size: 3.5rem;
            font-weight: 700;
            margin: 30px 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 4px;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.6),
                         0 0 40px rgba(245, 158, 11, 0.4),
                         0 0 60px rgba(13, 71, 161, 0.4);
            animation: logoFloat 5s ease-in-out infinite 0.5s;
            background: linear-gradient(135deg, #f59e0b, #0d47a1, #f59e0b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .company-tagline {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1.8rem;
            font-weight: 300;
            margin-top: 15px;
            letter-spacing: 2px;
            text-shadow: 0 0 15px rgba(255, 255, 255, 0.3);
            animation: logoFloat 6s ease-in-out infinite 1s;
        }

        .logo-version {
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.2rem;
            font-weight: 400;
            margin-top: 20px;
            font-style: italic;
        }

        .creation-date {
            color: rgba(255, 255, 255, 0.6);
            font-size: 1rem;
            font-weight: 400;
            margin-top: 15px;
            font-style: italic;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .logo-image {
                width: 300px;
                padding: 15px;
            }
            
            .company-text {
                font-size: 2.5rem;
                letter-spacing: 3px;
            }
            
            .company-tagline {
                font-size: 1.4rem;
            }
        }

        @media (max-width: 480px) {
            .logo-image {
                width: 250px;
                padding: 10px;
            }
            
            .company-text {
                font-size: 2rem;
                letter-spacing: 2px;
            }
            
            .company-tagline {
                font-size: 1.2rem;
            }
        }

        /* Enhanced glow effects */
        .meta-humans-logo::before {
            content: '';
            position: absolute;
            top: -25px;
            left: -25px;
            right: -25px;
            bottom: -25px;
            background: radial-gradient(circle, 
                rgba(245, 158, 11, 0.1) 0%, 
                rgba(13, 71, 161, 0.1) 50%, 
                transparent 100%);
            border-radius: 30px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .meta-humans-logo:hover::before {
            opacity: 1;
            animation: logoFloat 3s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <!-- Animated Meta Humans Logo -->
        <div class="meta-humans-logo">
            <img src="<?php echo $triangle_logo; ?>" 
                 alt="Meta Humans Logo" 
                 class="logo-image">
        </div>
        
        <div class="company-text">META HUMANS</div>
        <div class="company-tagline">Human interaction at infinite scale</div>
        <div class="logo-version">Alternative Triangle Logo Version</div>
        <div class="creation-date">Created: <?php echo $created_date; ?></div>
    </div>
</body>
</html>
