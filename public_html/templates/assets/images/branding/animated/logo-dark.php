<?php
// Meta Humans - Animated Black Logo (PHP Version)
// Dynamic asset path configuration
$asset_base = '/templates/assets/images/branding';
$black_logo = $asset_base . '/black/logo-black-1000.png';
$created_date = date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans - Animated Black Logo</title>
    <style>
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 50%, #dee2e6 100%);
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
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
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

        @keyframes logoRotate3D {
            0% {
                transform: rotateY(0deg) rotateX(0deg) rotateZ(0deg);
            }
            25% {
                transform: rotateY(90deg) rotateX(12deg) rotateZ(6deg);
            }
            50% {
                transform: rotateY(180deg) rotateX(0deg) rotateZ(0deg);
            }
            75% {
                transform: rotateY(270deg) rotateX(-12deg) rotateZ(-6deg);
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
                transform: scale(1.12);
                opacity: 0.95;
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

        .meta-humans-black-logo:active {
            animation: logoPulse 0.5s ease-in-out;
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

        .company-text {
            color: #2c3e50;
            font-size: 3.5rem;
            font-weight: 800;
            margin: 30px 0 15px 0;
            text-transform: uppercase;
            letter-spacing: 4px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            animation: logoFloat 6s ease-in-out infinite 0.5s;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .company-tagline {
            color: #34495e;
            font-size: 1.8rem;
            font-weight: 400;
            margin-top: 15px;
            letter-spacing: 2px;
            text-shadow: 0 1px 5px rgba(0, 0, 0, 0.1);
            animation: logoFloat 7s ease-in-out infinite 1s;
        }

        .creation-date {
            color: rgba(52, 73, 94, 0.7);
            font-size: 1rem;
            font-weight: 400;
            margin-top: 20px;
            font-style: italic;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .black-logo-image {
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
            .black-logo-image {
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
            animation: logoFloat 4s ease-in-out infinite;
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
        
        <div class="company-text">META HUMANS</div>
        <div class="company-tagline">Human interaction at infinite scale</div>
        <div class="creation-date">Created: <?php echo $created_date; ?></div>
    </div>
</body>
</html>
