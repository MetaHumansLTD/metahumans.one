<?php
if (php_sapi_name() !== 'cli') {
    $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
    header('Location: /hub/index.php' . $qs, true, 302);
    exit;
}
require_once __DIR__ . '/hub/index.php';
exit;
?>
            border: 1px solid rgba(0, 212, 255, 0.3);
            border-radius: 15px;
            padding: 2rem;
            backdrop-filter: blur(10px);
            margin-bottom: 3rem;
        }
        
        .notice-block {
            background: rgba(0, 212, 255, 0.05);
            border: 1px solid rgba(0, 212, 255, 0.2);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: all 0.3s ease;
        }
        
        .notice-block:hover {
            background: rgba(0, 212, 255, 0.1);
            border-color: rgba(0, 212, 255, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 212, 255, 0.2);
        }
        
        .notice-block:last-child {
            margin-bottom: 0;
        }
        
        .notice-block i {
            color: var(--launch-gold);
            font-size: 1.2rem;
            margin-top: 0.2rem;
            flex-shrink: 0;
            animation: pulse 2s ease-in-out infinite;
        }
        
        .notice-block p {
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
            margin: 0;
            font-size: 0.95rem;
        }
        
        .hero-countdown-section {
            width: 100%;
            display: flex;
            justify-content: center;
        }
        
        .launch-date {
            display: inline-block;
            background: var(--gradient-primary);
            color: var(--dark-bg);
            padding: var(--spacing-sm) var(--spacing-lg);
            border-radius: var(--radius-full);
            font-size: var(--font-size-lg);
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: var(--spacing-xl);
            animation: pulseGlow 3s ease-in-out infinite;
        }
        
        @keyframes pulseGlow {
            0%, 100% {
                box-shadow: 0 0 20px rgba(0, 212, 255, 0.4);
                transform: scale(1);
            }
            50% {
                box-shadow: 0 0 40px rgba(0, 212, 255, 0.8);
                transform: scale(1.05);
            }
        }
        
        .hero-title {
            font-family: var(--font-family-heading);
            font-size: clamp(3rem, 8vw, 6rem);
            font-weight: 900;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: var(--spacing-lg);
            line-height: 1.2;
        }
        
        .hero-subtitle {
            font-size: var(--font-size-2xl);
            color: var(--gray-text);
            margin-bottom: var(--spacing-2xl);
            font-weight: 300;
        }
        
        .countdown-timer {
            display: flex !important;
            justify-content: center;
            align-items: center;
            gap: 1.5rem;
            max-width: 100%;
            margin: 0;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
            overflow-x: visible;
            padding: 1.5rem;
            background: rgba(0, 212, 255, 0.1) !important;
            backdrop-filter: blur(20px);
            border: 2px solid rgba(0, 212, 255, 0.3) !important;
            border-radius: 12px;
            box-shadow: 
                0 8px 32px rgba(0, 212, 255, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            opacity: 1 !important;
            visibility: visible !important;
        }
        
        .countdown-unit {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(0, 212, 255, 0.3);
            border-radius: var(--radius-lg);
            padding: var(--spacing-sm) var(--spacing-lg);
            text-align: center;
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
            min-width: 270px;
            flex: 1;
            display: inline-block;
        }
        
        .countdown-unit::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform var(--transition-normal);
        }
        
        .countdown-unit:hover::before {
            transform: scaleX(1);
        }
        
        .countdown-unit:hover {
            border-color: rgba(0, 212, 255, 0.5);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.2);
            transform: translateY(-2px);
        }
        
        .countdown-number {
            font-family: var(--font-family-heading);
            font-size: 3.5rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
            line-height: 1;
            margin-bottom: var(--spacing-sm);
        }
        
        .countdown-label {
            font-size: var(--font-size-base);
            color: var(--gray-text);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 600;
        }
        
        /* Security Section */
        .security-section {
            padding: var(--spacing-2xl) 0;
            background: var(--dark-bg);
            position: relative;
        }
        
        .security-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 var(--spacing-xl);
        }
        
        .section-title {
            font-family: var(--font-family-heading);
            font-size: var(--font-size-4xl);
            text-align: center;
            margin-bottom: var(--spacing-xl);
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .security-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: var(--spacing-xl);
            margin-top: var(--spacing-2xl);
        }
        
        .security-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
        }
        
        .security-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform var(--transition-normal);
        }
        
        .security-card:hover::before {
            transform: scaleX(1);
        }
        
        .security-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-primary);
            border-color: rgba(0, 212, 255, 0.3);
        }
        
        .security-icon {
            width: 60px;
            height: 60px;
            background: var(--gradient-primary);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--spacing-lg);
        }
        
        .security-icon i {
            font-size: 1.5rem;
            color: var(--dark-bg);
        }
        
        .security-card h3 {
            font-size: var(--font-size-xl);
            color: var(--light-text);
            margin-bottom: var(--spacing-md);
            font-weight: 600;
        }
        
        .security-card p {
            color: var(--gray-text);
            line-height: 1.6;
            margin-bottom: var(--spacing-md);
        }
        
        /* AI Capabilities Section */
        .capabilities-section {
            padding: var(--spacing-2xl) 0;
            background: var(--darker-bg);
            position: relative;
        }
        
        .capabilities-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 var(--spacing-xl);
        }
        
        .capabilities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--spacing-xl);
            margin-top: var(--spacing-2xl);
        }
        
        .capability-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(124, 58, 237, 0.2);
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            text-align: center;
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
        }
        
        .capability-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gradient-secondary);
            opacity: 0;
            transition: opacity var(--transition-normal);
        }
        
        .capability-card:hover::before {
            opacity: 0.05;
        }
        
        .capability-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-secondary);
            border-color: rgba(124, 58, 237, 0.4);
        }
        
        .capability-icon {
            width: 80px;
            height: 80px;
            background: var(--gradient-secondary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto var(--spacing-lg);
            position: relative;
            z-index: 1;
        }
        
        .capability-icon i {
            font-size: 2rem;
            color: var(--light-text);
        }
        
        .capability-card h3 {
            font-size: var(--font-size-xl);
            color: var(--light-text);
            margin-bottom: var(--spacing-md);
            font-weight: 600;
            position: relative;
            z-index: 1;
        }
        
        .capability-card p {
            color: var(--gray-text);
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }
        
        /* Expert Support Section */
        .support-section {
            padding: var(--spacing-2xl) 0;
            background: var(--dark-bg);
            position: relative;
        }
        
        .support-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 var(--spacing-xl);
            text-align: center;
        }
        
        .support-card {
            background: var(--gradient-glass);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 212, 255, 0.3);
            border-radius: var(--radius-xl);
            padding: var(--spacing-2xl);
            margin-top: var(--spacing-2xl);
            position: relative;
            overflow: hidden;
        }
        
        .support-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gradient-primary);
            opacity: 0.05;
        }
        
        .support-icon {
            width: 100px;
            height: 100px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto var(--spacing-lg);
            position: relative;
            z-index: 1;
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .support-icon i {
            font-size: 2.5rem;
            color: var(--dark-bg);
        }
        
        .support-card h3 {
            font-size: var(--font-size-3xl);
            color: var(--light-text);
            margin-bottom: var(--spacing-lg);
            font-weight: 600;
            position: relative;
            z-index: 1;
        }
        
        .support-card p {
            color: var(--gray-text);
            font-size: var(--font-size-lg);
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }
        
        /* Call to Action */
        .cta-section {
            padding: var(--spacing-2xl) 0;
            background: var(--darker-bg);
            text-align: center;
        }
        
        .cta-content {
            max-width: 600px;
            margin: 0 auto;
            padding: 0 var(--spacing-xl);
        }
        
        .cta-button {
            display: inline-block;
            background: var(--gradient-primary);
            color: var(--dark-bg);
            padding: var(--spacing-lg) var(--spacing-2xl);
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: var(--font-size-lg);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all var(--transition-normal);
            margin-top: var(--spacing-xl);
            box-shadow: var(--shadow-primary);
        }
        
        .cta-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0, 212, 255, 0.4);
        }
        
        .hero-live-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--spacing-lg);
        }
        
        .live-indicator {
            background: var(--gradient-primary);
            color: var(--dark-bg);
            padding: var(--spacing-md) var(--spacing-xl);
            border-radius: var(--radius-full);
            font-size: var(--font-size-xl);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            animation: pulseGlow 2s ease-in-out infinite;
        }
        
        .revolution-text {
            color: var(--primary-color);
            font-size: clamp(1.5rem, 4vw, 3rem);
            margin: 0;
            font-weight: 300;
        }
        
        /* Responsive Design - Updated for unified hero */
        @media (max-width: 1200px) {
            .hero-container {
                max-width: 95%;
                padding: var(--spacing-xl);
            }
        }
        
        @media (max-width: 768px) {
            .hero-container {
                padding: var(--spacing-lg);
                gap: var(--spacing-xl);
            }
            
            .hero-main-title {
                font-size: clamp(2rem, 8vw, 4rem);
            }
            
            .hero-tagline {
                font-size: clamp(0.8rem, 3vw, 1.2rem);
            }
            
            .countdown-timer {
                gap: var(--spacing-md);
                padding: var(--spacing-md);
            }
            
            .countdown-unit {
                min-width: 200px;
                padding: var(--spacing-xs) var(--spacing-md);
            }
            
            .countdown-number {
                font-size: 2.8rem;
            }
            
            .countdown-label {
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 480px) {
            .hero-container {
                padding: var(--spacing-md);
                gap: var(--spacing-lg);
            }
            
            .countdown-timer {
                gap: var(--spacing-sm);
                padding: var(--spacing-sm);
            }
            
            .countdown-unit {
                min-width: 150px;
                padding: var(--spacing-xs) var(--spacing-sm);
            }
            
            .countdown-number {
                font-size: 2.2rem;
            }
            
            .countdown-label {
                font-size: 0.8rem;
            }
        }
        
        /* Footer adjustments */
        .meta-footer {
            margin-top: 0;
        }
        
        /* Force visibility for all hero content */
        .launch-hero * {
            opacity: 1 !important;
            visibility: visible !important;
        }
        
        .hero-title-section {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 1.5rem !important;
            width: 100% !important;
            opacity: 1 !important;
            visibility: visible !important;
        }
        
        .hero-launch-notice {
            background: rgba(0, 212, 255, 0.1) !important;
            border: 1px solid rgba(0, 212, 255, 0.3) !important;
            border-radius: 15px !important;
            padding: 2rem !important;
            backdrop-filter: blur(10px);
            margin-bottom: 3rem !important;
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
        }
        
        /* Ensure hamburger menu spans are visible - Override header.php styles */
        .hamburger-menu span {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            background: #00d4ff !important;
            width: 20px !important;
            height: 3px !important;
            border-radius: 2px !important;
            transition: all 0.3s ease !important;
            margin: 2px 0 !important;
            box-shadow: 0 0 3px rgba(0, 212, 255, 0.5) !important;
        }
        
        /* Ensure hamburger menu button is properly styled */
        .hamburger-menu {
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            align-items: center !important;
            gap: 2px !important;
            background: rgba(0, 212, 255, 0.1) !important;
            border: 1px solid rgba(0, 212, 255, 0.3) !important;
        }
        
        /* Footer adjustments - prevent large gaps */
        .meta-footer {
            margin-top: 0;
        }
        
        /* IMPROVED LAYOUT FOR INDEX.PHP - Allow header spacing */
        html {
            margin: 0;
            overflow-x: hidden;
        }
        
        body {
            margin: 0;
            height: fit-content;
            min-height: auto;
            max-height: none;
            overflow-x: hidden;
            /* Allow header content spacing from global-ui settings */
        }
        
        .main-content {
            min-height: auto !important;
            height: fit-content !important;
            margin-bottom: 0 !important;
            padding-bottom: 0 !important;
            /* Respect header content spacing from global settings */
        }
        
        /* Force all sections to have no bottom spacing */
        section {
            margin-bottom: 0 !important;
            padding-bottom: 2rem !important;
        }
        
        .cta-section {
            padding: 2rem 0 0 0 !important;
            margin-bottom: 0 !important;
        }
        
        /* Prevent widget-induced blank space but allow animation elements */
        .widget-container:not([data-background-effect]) {
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Ensure animation elements are properly positioned */
        [data-background-effect] {
            position: relative !important;
            z-index: 1 !important;
        }
        
        /* Force footer to stick to content */
        .meta-footer {
            margin-top: 0 !important;
            padding-top: 1rem !important;
        }
        
        /* Cleanup any floating elements */
        body::after {
            content: '';
            display: block;
            height: 0 !important;
            clear: both;
        }
    </style>
    
</head>
<body>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>

    <?php
    // Include animation widget for background effects
    if (file_exists(__DIR__ . '/templates/widgets/animations/animation.php')) {
        include_once __DIR__ . '/templates/widgets/animations/animation.php';
        initializeAnimationWidgetContent();
    }
    ?>
    
    <!-- Initialize animation widget after DOM is ready -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof window.AnimationWidget !== 'undefined') {
                setTimeout(() => {
                    console.log('🎨 Initializing animation widget...');
                    if (typeof window.AnimationWidget.initializeBackgroundEffects === 'function') {
                        window.AnimationWidget.initializeBackgroundEffects();
                        console.log('✅ Animation widget initialized');
                    }
                }, 500);
            }
        });
    </script>
    
    <!-- Include Header -->
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Launch Hero Section -->
        <section class="launch-hero" role="banner" data-css-animation="fade-in" data-animation-duration="1.5s">
            <div class="hero-background" data-background-effect="birds"></div>
            <div class="hero-container">
                <div class="hero-title-section">
                    <h1 class="hero-main-title" data-css-animation="bounce" data-animation-delay="0.5s">META HUMANS™</h1>
                    <div class="hero-icon" style="margin-top: 10px;">
                        <i class="fa fa-metahumans" style="font-size: 48px; line-height: 1; display: inline-block;"></i>
                    </div>
                    <p class="hero-tagline" data-css-animation="slide-in-left" data-animation-delay="0.8s">HUMAN INTERACTION AT INFINITE SCALE™</p>
                    <div class="hero-launch-notice">
                        <div class="notice-block">
                            <i class="fas fa-rocket"></i>
                            <p>We apologize for the delays. We are implementing the biometric security and the activation of all the different systems. Take notice that https://metahumans.ltd is the holdings company. https://metahumans.one will contain the services we deliver.</p>
							
							</div>
                        

                    </div>
                </div>
                
                <!-- Force show countdown since launch date hasn't passed -->
                <div class="hero-countdown-section">
                    <div class="countdown-timer" id="countdown">
                        <div class="countdown-unit">
                            <span class="countdown-number" id="days">-7</span>
                            <span class="countdown-label">Days</span>
                        </div>
                        <div class="countdown-unit">
                            <span class="countdown-number" id="hours">00</span>
                            <span class="countdown-label">Hours</span>
                        </div>
                        <div class="countdown-unit">
                            <span class="countdown-number" id="minutes">00</span>
                            <span class="countdown-label">Minutes</span>
                        </div>
                        <div class="countdown-unit">
                            <span class="countdown-number" id="seconds">00</span>
                            <span class="countdown-label">Seconds</span>
                        </div>
                    </div>
                </div>
                
                <?php if ($is_launch_day): ?>
                    <div class="hero-live-section">
                        <div class="live-indicator">
                            <i class="fas fa-check-circle"></i>
                            WE ARE LIVE!
                        </div>
                        <h2 class="revolution-text">The Revolution Has Begun</h2>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        
        <!-- Security & Authentication Section -->
        <section class="security-section" id="security">
            <div class="security-content">
                <h2 class="section-title">Ultra-Secure Authentication</h2>
                <p style="text-align: center; color: var(--gray-text); font-size: var(--font-size-lg); max-width: 800px; margin: 0 auto;">
                    Experience the most advanced, non-invasive login system powered by Meta Humans authentication. 
                    Your privacy and security are our absolute priority.
                </p>
                
                <div class="security-grid">
                    <div class="security-card">
                        <div class="security-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3>Zero Data Storage</h3>
                        <p>We never store your personal information on our servers. Meta Humans ensures your data stays where it belongs - with you.</p>
                    </div>
                    
                    <div class="security-card">
                        <div class="security-icon">
                            <i class="fas fa-fingerprint"></i>
                        </div>
                        <h3>Non-Invasive Authentication</h3>
                        <p>Our biometric systems are completely non-invasive, using advanced patterns that never compromise your biological data.</p>
                    </div>
                    
                    <div class="security-card">
                        <div class="security-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <h3>End-to-End Encryption</h3>
                        <p>Every interaction is protected with military-grade encryption. Your sessions are secured from start to finish.</p>
                    </div>
                    
                    <div class="security-card">
                        <div class="security-icon">
                            <i class="fas fa-user-secret"></i>
                        </div>
                        <h3>Anonymous by Design</h3>
                        <p>Meta Humans architecture ensures complete anonymity. We can't track you even if we wanted to.</p>
                    </div>
                    
                    <div class="security-card">
                        <div class="security-icon">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <h3>Distributed Security</h3>
                        <p>Our distributed authentication network means no single point of failure and maximum uptime.</p>
                    </div>
                    
                    <div class="security-card">
                        <div class="security-icon">
                            <i class="fas fa-certificate"></i>
                        </div>
                        <h3>Industry Compliance</h3>
                        <p>Fully compliant with GDPR, CCPA, and international privacy standards. Your rights are protected by law.</p>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- AI Capabilities Section -->
        <section class="capabilities-section" id="capabilities">
            <div class="capabilities-content">
                <h2 class="section-title">Infinite AI Possibilities</h2>
                <p style="text-align: center; color: var(--gray-text); font-size: var(--font-size-lg); max-width: 800px; margin: 0 auto;">
                    Unleash the power of Meta Humans artificial intelligence. Create, design, and innovate like never before.
                </p>
                
                <div class="capabilities-grid">
                    <div class="capability-card">
                        <div class="capability-icon">
                            <i class="fas fa-robot"></i>
                        </div>
                        <h3>Instant Meta Human Creation</h3>
                        <p>Generate fully-functional Meta Humans on demand. Customize appearance, personality, and capabilities in real-time.</p>
                    </div>
                    
                    <div class="capability-card">
                        <div class="capability-icon">
                            <i class="fas fa-magic"></i>
                        </div>
                        <h3>AI-Powered Design</h3>
                        <p>Create anything you can imagine instantly. From graphics to full applications, our AI makes it possible in seconds.</p>
                    </div>
                    
                    <div class="capability-card">
                        <div class="capability-icon">
                            <i class="fas fa-brain"></i>
                        </div>
                        <h3>Adaptive Intelligence</h3>
                        <p>Our AI learns and adapts to your needs, becoming more powerful and personalized with every interaction.</p>
                    </div>
                    
                    <div class="capability-card">
                        <div class="capability-icon">
                            <i class="fas fa-infinity"></i>
                        </div>
                        <h3>Infinite Scalability</h3>
                        <p>Handle unlimited users and interactions simultaneously. Our AI scales infinitely to meet any demand.</p>
                    </div>
                    
                    <div class="capability-card">
                        <div class="capability-icon">
                            <i class="fas fa-palette"></i>
                        </div>
                        <h3>Creative Synthesis</h3>
                        <p>Combine multiple AI models to create unprecedented creative solutions. Art, music, writing, and more.</p>
                    </div>
                    
                    <div class="capability-card">
                        <div class="capability-icon">
                            <i class="fas fa-rocket"></i>
                        </div>
                        <h3>Real-Time Generation</h3>
                        <p>Watch your ideas come to life instantly. No waiting, no processing delays - just pure creative speed.</p>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Expert Support Section -->
        <section class="support-section" id="support">
            <div class="support-content">
                <h2 class="section-title">Expert Support Team</h2>
                
                <div class="support-card">
                    <div class="support-icon">
                        <i class="fas fa-video"></i>
                    </div>
                    <h3>Live Video Assistance</h3>
                    <p>
                        Need help bringing your vision to life? Our team of expert developers and designers are standing by 
                        for live video consultations. Watch your projects develop in real-time as our experts guide you 
                        through every step of the creation process.
                    </p>
                    <p style="margin-top: var(--spacing-lg); font-weight: 600; color: var(--primary-color);">
                        <i class="fas fa-clock"></i> Available 24/7 • 
                        <i class="fas fa-globe"></i> Worldwide Coverage • 
                        <i class="fas fa-shield-check"></i> Secure Sessions
                    </p>
                </div>
            </div>
        </section>
        
        <!-- Call to Action -->
        <section class="cta-section">
            <div class="cta-content">
                <h2 class="section-title">Need a real human developer?</h2>
                <p style="color: var(--gray-text); font-size: var(--font-size-lg);">
                    Join the revolution in human-AI interaction. Experience infinite possibilities with complete security.
                </p>
            </div>
        </section>
    </main>
    
    <!-- Include Footer -->

    
    <!-- JavaScript for Countdown Timer -->
    <script>
        // Countdown Timer with Enhanced Animation
        function updateCountdown() {
            const launchDate = new Date('2025-11-10T16:00:00Z').getTime();
            const now = new Date().getTime();
            const distance = launchDate - now;
            
            if (distance < 0) {
                // Launch day has arrived
                const countdownEl = document.getElementById('countdown');
                if (countdownEl) {
                    countdownEl.innerHTML = `
                        <div style="color: var(--primary-color); font-size: 2rem; text-align: center; padding: 2rem;">
                            <i class="fas fa-rocket" style="font-size: 3rem; margin-bottom: 1rem; display: block;"></i>
                            🚀 We Are Live! 🚀
                        </div>
                    `;
                }
                return;
            }
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            // Update with animation
            updateCountdownUnit('days', days);
            updateCountdownUnit('hours', hours);
            updateCountdownUnit('minutes', minutes);
            updateCountdownUnit('seconds', seconds);
        }
        
        function updateCountdownUnit(id, value) {
            const element = document.getElementById(id);
            if (element) {
                const formattedValue = value.toString().padStart(2, '0');
                if (element.textContent !== formattedValue) {
                    element.style.transform = 'scale(1.1)';
                    element.style.transition = 'transform 0.2s ease';
                    
                    setTimeout(() => {
                        element.textContent = formattedValue;
                        element.style.transform = 'scale(1)';
                    }, 100);
                }
            }
        }
        
        // Initialize countdown - always run since we show both states
        // Update countdown every second
        setInterval(updateCountdown, 1000);
        updateCountdown(); // Initial call
        
        // Add loading animation to countdown units
        document.addEventListener('DOMContentLoaded', function() {
            const units = document.querySelectorAll('.countdown-unit');
            units.forEach((unit, index) => {
                unit.style.opacity = '0';
                unit.style.transform = 'translateY(20px)';
                unit.style.transition = 'all 0.6s ease';
                
                setTimeout(() => {
                    unit.style.opacity = '1';
                    unit.style.transform = 'translateY(0)';
                }, 200 * index);
            });
        });
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
        
        // Add scroll animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        // Observe all cards
        document.querySelectorAll('.security-card, .capability-card, .support-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.6s ease';
            observer.observe(card);
        });
        
        // Ensure hamburger menu functionality and guest status
        window.addEventListener('load', function() {
            // Force guest status in realm indicator
            forceGuestStatus();
            
            // ELIMINATE BLANK SPACE AGGRESSIVELY - but protect animations
            setTimeout(function() {
                // Skip cleanup if animation is active
                if (window.vantaAnimationInitialized) {
                    console.log('⚠️ Skipping aggressive cleanup - animation is active');
                    return;
                }
                
                // Force body to exact content height
                document.body.style.height = 'fit-content';
                document.body.style.minHeight = 'auto';
                
                // Remove any large empty elements but protect animation elements
                const allElements = document.querySelectorAll('*');
                allElements.forEach(el => {
                    if (el.innerHTML.trim() === '' && 
                        el.offsetHeight > 100 && 
                        !el.classList.contains('hero-background') &&
                        el.id !== 'vanta-bg' &&
                        !el.classList.contains('vanta-protected') &&
                        !el.id.includes('vanta')) {
                        el.style.display = 'none';
                    }
                });
                
                // Hide empty widget containers but preserve animation elements
                const widgetContainers = document.querySelectorAll('[class*="widget"]:not(#vanta-bg)');
                widgetContainers.forEach(widget => {
                    if (widget.id !== 'vanta-bg' && widget.offsetHeight > 200 && widget.innerHTML.trim() === '') {
                        widget.style.display = 'none';
                    }
                });
                
                // Ensure vanta-bg element remains visible
                const vantaBg = document.getElementById('vanta-bg');
                if (vantaBg) {
                    vantaBg.style.display = 'block';
                    vantaBg.style.visibility = 'visible';
                    console.log('✅ VANTA background element preserved');
                }
                
                console.log('Blank space elimination completed');
            }, 500);
            
            // Check animation elements after page load
            setTimeout(function() {
                console.log('After load - Background elements:', document.querySelectorAll('[data-background-effect]').length);
                const heroBackground = document.querySelector('.hero-background');
                if (heroBackground) {
                    console.log('Hero background element found:', heroBackground);
                    console.log('Hero background data attribute:', heroBackground.getAttribute('data-background-effect'));
                } else {
                    console.log('Hero background element NOT found');
                }
            }, 1000);
        });
        
        // Additional DOM ready handler
        document.addEventListener('DOMContentLoaded', function() {
            // Force guest status multiple times
            setTimeout(forceGuestStatus, 100);
            setTimeout(forceGuestStatus, 500);
        });
        
        function forceHamburgerVisibility() {
            const hamburger = document.querySelector('.hamburger-menu');
            if (hamburger) {
                hamburger.style.display = 'flex';
                hamburger.style.visibility = 'visible';
                hamburger.style.opacity = '1';
                hamburger.style.position = 'relative';
                hamburger.style.zIndex = '1000';
                
                const spans = hamburger.querySelectorAll('span');
                spans.forEach((span, index) => {
                    span.style.display = 'block';
                    span.style.visibility = 'visible';
                    span.style.opacity = '1';
                    span.style.backgroundColor = '#00d4ff';
                    span.style.width = '20px';
                    span.style.height = '2px';
                    span.style.margin = '2px 0';
                    span.style.borderRadius = '1px';
                    span.style.position = 'relative';
                });
                
                console.log('Hamburger menu forced visible with', spans.length, 'spans');
            } else {
                console.log('Hamburger menu not found, creating backup');
                createBackupHamburger();
            }
        }
        
        function createBackupHamburger() {
            // Check if backup already exists
            if (document.querySelector('.launch-hamburger')) return;
            
            const backupHamburger = document.createElement('div');
            backupHamburger.className = 'launch-hamburger';
            backupHamburger.onclick = function() {
                // Toggle the active state for animation
                backupHamburger.classList.toggle('active');
                
                // Try to call the original toggleSidebar function
                if (typeof toggleSidebar === 'function') {
                    toggleSidebar();
                } else if (typeof openSidebar === 'function') {
                    openSidebar();
                } else {
                    // Manually toggle sidebar
                    const sidebar = document.getElementById('sidebarMenu');
                    if (sidebar) {
                        sidebar.classList.toggle('active');
                        const overlay = document.getElementById('sidebarOverlay');
                        if (overlay) overlay.classList.toggle('active');
                        
                        // Update body class for menu state
                        document.body.classList.toggle('menu-open');
                    }
                }
            };
            
            // Create the three bars
            for (let i = 0; i < 3; i++) {
                const span = document.createElement('span');
                backupHamburger.appendChild(span);
            }
            
            document.body.appendChild(backupHamburger);
            console.log('Backup hamburger menu created');
        }
        
        function forceGuestStatus() {
            const realmIndicator = document.querySelector('.realm-indicator');
            if (realmIndicator) {
                realmIndicator.style.color = '#00d4ff';
                const realmIcon = realmIndicator.querySelector('i');
                const realmText = realmIndicator.querySelector('span');
                
                if (realmIcon) {
                    realmIcon.className = 'fas fa-globe';
                }
                if (realmText) {
                    realmText.textContent = 'Guest';
                }
                console.log('Guest status forced');
            }
        }
        
        // Load and apply saved animations for this page
        function loadPageAnimation() {
            // Prevent multiple initialization attempts
            if (window.animationLoadingInProgress || window.vantaAnimationInitialized) {
                console.log('⚠️ Animation loading already in progress or completed');
                return;
            }
            
            window.animationLoadingInProgress = true;
            const currentPath = window.location.pathname;
            console.log('🎨 Loading animation for path:', currentPath);
            
            fetch('/templates/widgets/animations/settings.php?action=get_saved_animations')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.animations) {
                        // Try to find animation for current page
                        let pageAnimation = null;
                        
                        // Check for exact path match or index.php match
                        for (const [key, animation] of Object.entries(data.animations)) {
                            if (key === currentPath || 
                                (currentPath === '/' && key.includes('/index.php')) ||
                                (currentPath.includes('index.php') && key.includes('index.php'))) {
                                pageAnimation = animation;
                                break;
                            }
                        }
                        
                        if (pageAnimation && pageAnimation.enabled) {
                            console.log('🎯 Found animation for page:', pageAnimation.animation);
                            applyBackgroundAnimation(pageAnimation.animation);
                        } else {
                            console.log('❌ No animation found for this page');
                        }
                    }
                })
                .catch(error => {
                    console.error('❌ Error loading page animation:', error);
                })
                .finally(() => {
                    window.animationLoadingInProgress = false;
                });
        }
        
        function applyBackgroundAnimation(animationType) {
            console.log('🎨 Applying animation via widget system:', animationType);
            
            // Update the hero-background element's data attribute
            const heroBackground = document.querySelector('.hero-background');
            if (heroBackground) {
                heroBackground.setAttribute('data-background-effect', animationType);
                console.log('✅ Updated hero-background data-background-effect to:', animationType);
                
                // Trigger animation widget initialization if available
                if (typeof window.AnimationWidget !== 'undefined' && typeof window.AnimationWidget.initializeBackgroundEffects === 'function') {
                    window.AnimationWidget.initializeBackgroundEffects();
                    console.log('✅ Animation widget re-initialized');
                } else {
                    console.log('⚠️ Animation widget not available, will be initialized on widget load');
                }
            } else {
                console.error('❌ Hero background element not found');
            }
        }
        
        // Load animation when page loads - with single execution guard
        if (!window.animationListenerAdded) {
            window.animationListenerAdded = true;
            document.addEventListener('DOMContentLoaded', loadPageAnimation);
            console.log('🎯 Animation loader event listener registered');
        }
    </script>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
    
