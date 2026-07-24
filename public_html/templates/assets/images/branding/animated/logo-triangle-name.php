<?php
// Meta Humans - Animated Triangle Logo with Name (PHP Version)
// Dynamic asset path configuration
$asset_base = '/templates/assets/images/branding';
$triangle_logo_400 = $asset_base . '/triangle/logo-triangle-400.png';
$created_date = date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans - Animated Logo</title>
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
            background: rgba(255, 255, 255, 0.02);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }
        
        /* Meta Humans Animated Logo Styles */
        @keyframes logoGoldBlueGlow {
            0%, 100% {
                filter: drop-shadow(0 0 15px rgba(245, 158, 11, 0.6)) 
                        drop-shadow(0 0 25px rgba(59, 130, 246, 0.6))
                        drop-shadow(0 0 35px rgba(245, 158, 11, 0.3))
                        drop-shadow(0 0 45px rgba(59, 130, 246, 0.3));
            }
            25% {
                filter: drop-shadow(0 0 20px rgba(245, 158, 11, 0.8)) 
                        drop-shadow(0 0 15px rgba(59, 130, 246, 0.4))
                        drop-shadow(0 0 40px rgba(245, 158, 11, 0.4))
                        drop-shadow(0 0 25px rgba(59, 130, 246, 0.2));
            }
            50% {
                filter: drop-shadow(0 0 15px rgba(245, 158, 11, 0.4)) 
                        drop-shadow(0 0 25px rgba(59, 130, 246, 0.8))
                        drop-shadow(0 0 25px rgba(245, 158, 11, 0.2))
                        drop-shadow(0 0 45px rgba(59, 130, 246, 0.4));
            }
            75% {
                filter: drop-shadow(0 0 18px rgba(245, 158, 11, 0.7)) 
                        drop-shadow(0 0 18px rgba(59, 130, 246, 0.7))
                        drop-shadow(0 0 30px rgba(245, 158, 11, 0.3))
                        drop-shadow(0 0 30px rgba(59, 130, 246, 0.3));
            }
        }

        @keyframes logoFloat {
            0%, 100% {
                transform: translateY(0px) scale(1) rotateZ(0deg);
            }
            25% {
                transform: translateY(-5px) scale(1.01) rotateZ(1deg);
            }
            50% {
                transform: translateY(-8px) scale(1.02) rotateZ(0deg);
            }
            75% {
                transform: translateY(-5px) scale(1.01) rotateZ(-1deg);
            }
        }

        @keyframes logoRotate {
            0% {
                transform: rotateY(0deg) rotateX(0deg);
            }
            25% {
                transform: rotateY(90deg) rotateX(5deg);
            }
            50% {
                transform: rotateY(180deg) rotateX(0deg);
            }
            75% {
                transform: rotateY(270deg) rotateX(-5deg);
            }
            100% {
                transform: rotateY(360deg) rotateX(0deg);
            }
        }

        @keyframes logoPulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.05);
                opacity: 0.95;
            }
        }

        .meta-humans-logo {
            position: relative;
            display: inline-block;
            animation: logoFloat 6s ease-in-out infinite;
            margin: 30px 0;
            perspective: 1000px;
            cursor: pointer;
        }

        .meta-humans-logo:hover {
            animation: logoRotate 3s ease-in-out;
            transform: scale(1.08);
        }

        .meta-humans-logo:active {
            animation: logoPulse 0.4s ease-in-out;
        }

        .meta-humans-logo .logo-image {
            width: 300px;
            height: auto;
            max-width: 100%;
            transition: all 0.3s ease;
            animation: logoGoldBlueGlow 4s ease-in-out infinite;
            transform-origin: center;
            border-radius: 12px;
            background: linear-gradient(135deg, 
                rgba(245, 158, 11, 0.03), 
                rgba(59, 130, 246, 0.03), 
                rgba(245, 158, 11, 0.03));
            padding: 15px;
            backdrop-filter: blur(8px);
        }

        .meta-humans-logo:hover .logo-image {
            transform: scale(1.05);
            filter: drop-shadow(0 0 25px rgba(245, 158, 11, 0.8)) 
                    drop-shadow(0 0 35px rgba(59, 130, 246, 0.8))
                    drop-shadow(0 0 45px rgba(255, 255, 255, 0.3)) !important;
            background: linear-gradient(135deg, 
                rgba(245, 158, 11, 0.1), 
                rgba(59, 130, 246, 0.1), 
                rgba(245, 158, 11, 0.1));
        }

        .company-text {
            color: white;
            font-size: 3rem;
            font-weight: 700;
            margin: 30px 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 3px;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.5),
                         0 0 30px rgba(245, 158, 11, 0.3),
                         0 0 40px rgba(59, 130, 246, 0.3);
            animation: logoFloat 4s ease-in-out infinite 0.5s;
            background: linear-gradient(135deg, #f59e0b, #3b82f6, #f59e0b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .company-tagline {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.5rem;
            font-weight: 300;
            margin-top: 10px;
            letter-spacing: 1px;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
            animation: logoFloat 5s ease-in-out infinite 1s;
        }

        .creation-date {
            color: rgba(255, 255, 255, 0.6);
            font-size: 1rem;
            font-weight: 400;
            margin-top: 20px;
            font-style: italic;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .logo-image {
                width: 250px;
                padding: 12px;
            }
            
            .company-text {
                font-size: 2.5rem;
                letter-spacing: 2px;
            }
            
            .company-tagline {
                font-size: 1.3rem;
            }
        }

        @media (max-width: 480px) {
            .logo-image {
                width: 200px;
                padding: 10px;
            }
            
            .company-text {
                font-size: 2rem;
                letter-spacing: 1px;
            }
            
            .company-tagline {
                font-size: 1.1rem;
            }
        }

        /* Enhanced background effect */
        .meta-humans-logo::before {
            content: '';
            position: absolute;
            top: -20px;
            left: -20px;
            right: -20px;
            bottom: -20px;
            background: radial-gradient(circle, 
                rgba(245, 158, 11, 0.08) 0%, 
                rgba(59, 130, 246, 0.08) 50%, 
                transparent 100%);
            border-radius: 25px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .meta-humans-logo:hover::before {
            opacity: 1;
            animation: logoFloat 2s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <!-- Animated Meta Humans Logo -->
        <div class="meta-humans-logo">
            <img src="<?php echo $triangle_logo_400; ?>" 
                 alt="Meta Humans Logo" 
                 class="logo-image">
        </div>
        
        <div class="company-text">META HUMANS</div>
        <div class="company-tagline">Human interaction at infinite scale</div>
        <div class="creation-date">Created: <?php echo $created_date; ?></div>
    </div>
</body>
</html>
