(function() {
    // Determine target script element and extract project ID
    var scriptEl = document.currentScript;
    if (!scriptEl) {
        var scripts = document.getElementsByTagName('script');
        for (var idx = 0; idx < scripts.length; idx++) {
            if (scripts[idx].src && (scripts[idx].src.indexOf('widget.js') !== -1 || scripts[idx].src.indexOf('maarksho') !== -1)) {
                scriptEl = scripts[idx];
                break;
            }
        }
    }
    if (!scriptEl) {
        var scripts = document.getElementsByTagName('script');
        scriptEl = scripts[scripts.length - 1];
    }

    var scriptSrc = scriptEl ? scriptEl.src : '';
    var projectId = 'default';
    if (scriptSrc && scriptSrc.indexOf('project=') !== -1) {
        projectId = scriptSrc.split('project=')[1].split('&')[0];
    } else if (scriptSrc && scriptSrc.indexOf('p=') !== -1) {
        projectId = scriptSrc.split('p=')[1].split('&')[0];
    }

    // Determine host domain of the script to resolve URLs correctly
    var apiHost = '';
    if (scriptSrc && (scriptSrc.indexOf('http://') === 0 || scriptSrc.indexOf('https://') === 0 || scriptSrc.indexOf('//') === 0)) {
        var parser = document.createElement('a');
        parser.href = scriptSrc;
        apiHost = parser.protocol + '//' + parser.host;
    } else {
        // Fallback to the current origin if relative or local
        apiHost = window.location.protocol + '//' + window.location.host;
    }

    // Avoid multiple instances
    if (document.getElementById('maarksho-chat-container')) {
        return;
    }

    // Create floating widget container
    var container = document.createElement('div');
    container.id = 'maarksho-chat-container';
    container.style.position = 'fixed';
    container.style.bottom = '20px';
    container.style.right = '20px';
    container.style.width = '75px';
    container.style.height = '75px';
    container.style.zIndex = '999999';
    container.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
    container.style.borderRadius = '50%';
    container.style.overflow = 'hidden';

    // Create the iframe pointing to our custom chat client
    var iframe = document.createElement('iframe');
    iframe.id = 'maarksho-chat-iframe';
    iframe.src = apiHost + '/widget-iframe.php?project=' + projectId + '&domain=' + encodeURIComponent(window.location.hostname);
    iframe.style.width = '100%';
    iframe.style.height = '100%';
    iframe.style.border = 'none';
    iframe.style.background = 'transparent';
    iframe.style.colorScheme = 'light';
    iframe.style.display = 'block';

    container.appendChild(iframe);
    document.body.appendChild(container);

    // Listen to events sent by our iframe to handle collapsing and expanding
    window.addEventListener('message', function(event) {
        if (event.origin !== apiHost) return;
        var data = event.data;
        if (typeof data === 'object' && data.type === 'maarksho-chat') {
            if (data.action === 'expand') {
                if (window.innerWidth < 480) {
                    container.style.width = '100%';
                    container.style.height = '100%';
                    container.style.bottom = '0px';
                    container.style.right = '0px';
                    container.style.borderRadius = '0px';
                } else {
                    container.style.width = '380px';
                    container.style.height = '620px';
                    container.style.bottom = '20px';
                    container.style.right = '20px';
                    container.style.borderRadius = '24px';
                }
                container.style.boxShadow = '0 12px 40px rgba(0, 0, 0, 0.18)';
            } else if (data.action === 'collapse') {
                container.style.width = '75px';
                container.style.height = '75px';
                container.style.bottom = '20px';
                container.style.right = '20px';
                container.style.borderRadius = '50%';
                container.style.boxShadow = 'none';
            }
        }
    });
})();
