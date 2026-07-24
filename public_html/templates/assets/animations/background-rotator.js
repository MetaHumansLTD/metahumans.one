// Prevent script from loading multiple times
if (typeof window.backgroundRotatorLoaded !== 'undefined') {
    console.log('Background rotator already loaded, skipping...');
} else {
window.backgroundRotatorLoaded = true;

// Use window object to prevent redeclaration conflicts
if (typeof window.vantaEffect === 'undefined') {
    window.vantaEffect = null;
}
if (typeof window.vantaEl === 'undefined') {
    window.vantaEl = "#vanta-bg";
}

// Meta Humans color palette (converted to hex)
if (typeof window.primaryColor === 'undefined') {
    window.primaryColor = 0x00d4ff; // Cyan
}
if (typeof window.secondaryColor === 'undefined') {
    window.secondaryColor = 0x7c3aed; // Purple  
}
if (typeof window.darkBg === 'undefined') {
    window.darkBg = 0x0a0a0a; // Dark background
}

// Complete list of Vanta effects
if (typeof window.effects === 'undefined') {
    window.effects = [
      () => VANTA.BIRDS({ 
        el: window.vantaEl, 
        color1: window.primaryColor,
        color2: window.secondaryColor,
        backgroundColor: window.darkBg,
        birdSize: 1.5,
        wingSpan: 25,
        speedLimit: 4
      }),
      () => VANTA.WAVES({ 
        el: window.vantaEl, 
        color: window.primaryColor,
        shininess: 50, 
        waveHeight: 15, 
        waveSpeed: 0.5,
        backgroundColor: window.darkBg
      }),
      () => VANTA.NET({ 
        el: window.vantaEl, 
        color: window.primaryColor, 
        backgroundColor: window.darkBg,
        points: 8,
        maxDistance: 20,
        spacing: 15
      }),
      () => VANTA.DOTS({ 
        el: window.vantaEl, 
        color: window.primaryColor, 
        color2: window.secondaryColor, 
        backgroundColor: window.darkBg,
        size: 3,
        spacing: 25
      }),
      () => VANTA.CELLS({ 
        el: window.vantaEl, 
        color1: window.primaryColor, 
        color2: window.secondaryColor,
        backgroundColor: window.darkBg,
        size: 1.5
      }),
      () => VANTA.FOG({ 
        el: window.vantaEl, 
        highlightColor: window.primaryColor, 
        midtoneColor: window.secondaryColor,
        baseColor: window.darkBg,
        blurFactor: 0.6
      }),
      () => VANTA.CLOUDS({ 
        el: window.vantaEl, 
        skyColor: window.darkBg,
        cloudColor: window.primaryColor,
        cloudShadowColor: window.secondaryColor,
        sunColor: window.primaryColor,
        speed: 1.0
      }),
      () => VANTA.TOPOLOGY({ 
        el: window.vantaEl, 
        color: window.primaryColor,
        backgroundColor: window.darkBg
      }),
      () => VANTA.TRUNK({ 
        el: window.vantaEl, 
        color: window.primaryColor,
        backgroundColor: window.darkBg
      }),
      () => VANTA.RINGS({ 
        el: window.vantaEl, 
        color: window.primaryColor,
        backgroundColor: window.darkBg
      }),
      () => VANTA.HALO({ 
        el: window.vantaEl, 
        color: window.primaryColor,
        backgroundColor: window.darkBg,
        size: 1.5
      })
    ];
}

if (typeof window.current === 'undefined') {
    window.current = 0;
}
if (typeof window.interval === 'undefined') {
    window.interval = 8000; // 8 seconds per background for slower transitions
}

window.setEffect = function(index) {
  if (window.vantaEffect) {
    try {
      window.vantaEffect.destroy();
    } catch(e) {
      console.log('Effect cleanup:', e);
    }
  }
  try {
    window.vantaEffect = window.effects[index % window.effects.length]();
  } catch(e) {
    console.log('Effect creation error:', e);
  }
}

// Prevent multiple initialization
if (typeof window.backgroundRotatorInitialized === 'undefined') {
    window.backgroundRotatorInitialized = true;
    
    // Wait for DOM to be ready and start rotation
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initBackgrounds);
    } else {
      initBackgrounds();
    }
}

window.initBackgrounds = function() {
  const bgElement = document.querySelector('#vanta-bg');
  if (bgElement) {
    window.setEffect(window.current);
    setInterval(() => {
      window.current++;
      window.setEffect(window.current);
    }, window.interval);
  } else {
    console.log('Vanta background element not found');
  }
}

} // End of backgroundRotatorLoaded check
