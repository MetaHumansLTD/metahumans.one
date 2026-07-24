<?php
// Meta Humans - Triangle Logo Animated (PHP Version)
// Dynamic asset path configuration
$asset_base = '/assets/images/branding';
$triangle_logo = $asset_base . '/triangle/logo-triangle-1000.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans - Triangle Logo Animated</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            background: rgba(0, 0, 0, 0.1);
            border-radius: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Meta Humans Triangle Logo Animation Styles */
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
                transform: translateY(-8px) scale(1.02);
            }
            50% {
                transform: translateY(-12px) scale(1.04);
            }
            75% {
                transform: translateY(-8px) scale(1.02);
            }
        }

        .meta-humans-triangle-logo {
            position: relative;
            display: inline-block;
            animation: logoFloat 6s ease-in-out infinite;
            margin: 30px 0;
            cursor: pointer;
            transition: all 0.4s ease;
        }

        .meta-humans-triangle-logo:hover {
            transform: scale(1.1);
            filter: drop-shadow(-15px -8px 20px rgba(245, 158, 11, 0.9)) 
                    drop-shadow(15px 8px 20px rgba(13, 71, 161, 0.9))
                    drop-shadow(-30px -15px 40px rgba(245, 158, 11, 0.6))
                    drop-shadow(30px 15px 40px rgba(13, 71, 161, 0.6))
                    drop-shadow(0 0 20px rgba(0, 0, 0, 0.5));
        }

        .triangle-logo-image {
            width: 400px;
            height: auto;
            max-width: 100%;
            transition: all 0.4s ease;
            animation: logoCombinedGlow 4.5s ease-in-out infinite;
            transform-origin: center;
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            backdrop-filter: blur(5px);
        }

        .company-text {
            color: #ffffff;
            font-size: 3.5rem;
            font-weight: 700;
            margin: 30px 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 4px;
            text-shadow: 0 0 20px rgba(245, 158, 11, 0.4),
                         0 0 40px rgba(13, 71, 161, 0.4),
                         0 0 60px rgba(0, 0, 0, 0.2);
            animation: logoFloat 5s ease-in-out infinite 0.7s;
        }
        
        .company-tagline {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.8rem;
            font-weight: 300;
            margin-top: 15px;
            letter-spacing: 2px;
            text-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            animation: logoFloat 6s ease-in-out infinite 1.2s;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .triangle-logo-image {
                width: 300px;
                padding: 10px;
            }
            
            .company-text {
                font-size: 2.5rem;
            }
            
            .company-tagline {
                font-size: 1.4rem;
            }
        }

        @media (max-width: 480px) {
            .triangle-logo-image {
                width: 250px;
                padding: 8px;
            }
            
            .company-text {
                font-size: 2rem;
            }
            
            .company-tagline {
                font-size: 1.2rem;
            }
        }

        /* Enhanced glow effects for dark theme */
        .meta-humans-triangle-logo::before {
            content: '';
            position: absolute;
            top: -15px;
            left: -15px;
            right: -15px;
            bottom: -15px;
            background: linear-gradient(45deg, 
                rgba(245, 158, 11, 0.2), 
                rgba(13, 71, 161, 0.2), 
                rgba(245, 158, 11, 0.2));
            border-radius: 25px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .meta-humans-triangle-logo:hover::before {
            opacity: 1;
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <!-- Animated Meta Humans Triangle Logo -->
        <div class="meta-humans-triangle-logo">
            <img src="<?php echo $triangle_logo; ?>" 
                 alt="Meta Humans Triangle Logo" 
                 class="triangle-logo-image">
        </div>
        
        <div class="company-text">META HUMANS</div>
        <div class="company-tagline">Human interaction at infinite scale</div>
    </div>
</body>
</html>
