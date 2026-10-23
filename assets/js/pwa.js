// EduERP Progressive Web App (PWA) Client Script
// Handles Service Worker registration and in-app install prompts

(function() {
    'use strict';

    // 1. Register Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/sw.js')
                .then(function(registration) {
                    // Registration successful
                })
                .catch(function(error) {
                    console.warn('[EduERP PWA] ServiceWorker registration failed:', error);
                });
        });
    }

    // 2. Handle 'beforeinstallprompt' for native install trigger
    window.deferredPWAInstallPrompt = null;

    window.addEventListener('beforeinstallprompt', function(e) {
        // Prevent default mini-infobar on mobile Chrome
        e.preventDefault();
        window.deferredPWAInstallPrompt = e;

        // Show any install buttons in the UI
        var installBtns = document.querySelectorAll('.pwa-install-btn');
        installBtns.forEach(function(btn) {
            btn.style.display = 'inline-flex';
            btn.classList.remove('d-none');
        });
    });

    // 3. Global function to trigger installation when button is clicked
    window.installEduERPApp = function() {
        if (!window.deferredPWAInstallPrompt) {
            // If already installed or on iOS/unsupported browser, show helpful instructions
            var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            if (isIOS) {
                alert('To install EduERP on iPhone/iPad:\n1. Tap the Share button (square with arrow) at the bottom.\n2. Scroll down and tap "Add to Home Screen".');
            } else {
                alert('EduERP is already installed or your browser supports installing via the address bar icon.');
            }
            return;
        }

        window.deferredPWAInstallPrompt.prompt();
        window.deferredPWAInstallPrompt.userChoice.then(function(choiceResult) {
            if (choiceResult.outcome === 'accepted') {
                console.log('[EduERP PWA] User installed the application');
            }
            window.deferredPWAInstallPrompt = null;
            document.querySelectorAll('.pwa-install-btn').forEach(function(btn) {
                btn.style.display = 'none';
            });
        });
    };

    // 4. Handle successful installation
    window.addEventListener('appinstalled', function() {
        console.log('[EduERP PWA] Application was successfully installed');
        window.deferredPWAInstallPrompt = null;
        document.querySelectorAll('.pwa-install-btn').forEach(function(btn) {
            btn.style.display = 'none';
        });
    });
})();
