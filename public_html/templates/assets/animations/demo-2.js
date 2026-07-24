(function() {
    // Early check - don't run if required elements don't exist
    if (!document.getElementById('large-header') || !document.getElementById('demo-canvas')) {
        console.warn('Demo-2: Required elements not found, skipping animation');
        return;
    }

    var width, height, largeHeader, canvas, ctx, circles, target, animateHeader = true;

    // Main
    try {
        if (initHeader()) {
            addListeners();
        }
    } catch (e) {
        console.error('Demo-2 animation error:', e);
    }

    function initHeader() {
        width = window.innerWidth;
        height = window.innerHeight;
        target = {x: 0, y: height};

        largeHeader = document.getElementById('large-header');
        if (!largeHeader) {
            console.warn('Demo-2: large-header element not found, skipping animation');
            return false;
        }
        largeHeader.style.height = height+'px';

        canvas = document.getElementById('demo-canvas');
        if (!canvas) {
            console.warn('Demo-2: demo-canvas element not found, skipping animation');
            return false;
        }
        canvas.width = width;
        canvas.height = height;
        ctx = canvas.getContext('2d');
        return true;

        // create particles
        circles = [];
        for(var x = 0; x < width*0.5; x++) {
            var c = new Circle();
            circles.push(c);
        }
        animate();
    }

    // Event handling
    function addListeners() {
        window.addEventListener('scroll', scrollCheck);
        window.addEventListener('resize', resize);
    }

    function scrollCheck() {
        if(document.body.scrollTop > height) animateHeader = false;
        else animateHeader = true;
    }

    function resize() {
        width = window.innerWidth;
        height = window.innerHeight;
        largeHeader.style.height = height+'px';
        canvas.width = width;
        canvas.height = height;
    }

    function animate() {
        if(animateHeader) {
            ctx.clearRect(0,0,width,height);
            for(var i in circles) {
                circles[i].draw();
            }
        }
        requestAnimationFrame(animate);
    }

    // Canvas manipulation
    function Circle() {
        var _this = this;

        // constructor
        (function() {
            _this.pos = {};
            init();
            console.log(_this);
        })();

        function init() {
            _this.pos.x = Math.random()*width;
            _this.pos.y = height+Math.random()*100;
            _this.alpha = 0.1+Math.random()*0.3;
            _this.scale = 0.1+Math.random()*0.3;
            _this.velocity = Math.random();
        }

        this.draw = function() {
            if(_this.alpha <= 0) {
                init();
            }
            _this.pos.y -= _this.velocity;
            _this.alpha -= 0.0005;
            ctx.beginPath();
            ctx.arc(_this.pos.x, _this.pos.y, _this.scale*10, 0, 2 * Math.PI, false);
            ctx.fillStyle = 'rgba(255,255,255,'+ _this.alpha+')';
            ctx.fill();
        };
    }

})();