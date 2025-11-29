document.addEventListener('DOMContentLoaded', function() {
    // Add debug logging to help identify when the script is loaded
    console.log('Ollama Summarizer summarize.js loaded');
    
    // Handle button click event
    function handleButtonClick(e) {
        console.log('Summarize button clicked');
        const button = e.currentTarget;
        const entryId = button.dataset.entryId;
        
        console.log('Processing click for entry ID: ' + entryId);
        
        // Disable the button and show loading state
        button.disabled = true;
        button.textContent = 'Summarizing...';
        
        // Get CSRF token from context
        const csrf = window.context.csrf;
        if (!csrf) {
            alert('Error: CSRF token not found');
            button.disabled = false;
            button.textContent = 'Summarize with AI';
            return;
        }
        
        // Send the request to the controller
        fetch('?c=FetchAndSummarizeWithOllama', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + encodeURIComponent(entryId) + '&_csrf=' + encodeURIComponent(csrf)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload the page to show the new summary
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'Unknown error occurred'));
                button.disabled = false;
                button.textContent = 'Summarize with AI';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error: ' + error.message);
            button.disabled = false;
            button.textContent = 'Summarize with AI';
        });
    }
    
    // Handle details expansion to auto-load HTML
    function handleDetailsToggle(e) {
        const details = e.currentTarget;

        // Only load if opening (not closing) and not already loaded
        if (!details.open || details.dataset.htmlLoaded === 'true') {
            return;
        }

        console.log('Loading HTML for expanded details');
        const entryId = details.dataset.entryId;
        const container = details.querySelector('.html-content-area');

        if (!container) {
            console.error('Could not find HTML content container');
            return;
        }

        const loadingDiv = container.querySelector('.html-loading');
        const contentDiv = container.querySelector('.html-content');

        // Mark as loaded to prevent duplicate requests
        details.dataset.htmlLoaded = 'true';

        // Get CSRF token from context
        const csrf = window.context.csrf;
        if (!csrf) {
            loadingDiv.style.display = 'none';
            contentDiv.innerHTML = '<em style="color: red;">Error: CSRF token not found</em>';
            contentDiv.style.display = 'block';
            return;
        }

        // Send the request to load sanitized HTML
        fetch('?c=GetSanitizedHtml', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'entry_id=' + encodeURIComponent(entryId) + '&_csrf=' + encodeURIComponent(csrf)
        })
        .then(response => response.json())
        .then(data => {
            loadingDiv.style.display = 'none';

            if (data.success) {
                contentDiv.innerHTML = data.html || '<em>No HTML content available</em>';
                contentDiv.style.display = 'block';
            } else {
                contentDiv.innerHTML = '<em style="color: red;">Error: ' + (data.error || 'Unknown error') + '</em>';
                contentDiv.style.display = 'block';
                // Allow retry by resetting the loaded flag
                details.dataset.htmlLoaded = 'false';
            }
        })
        .catch(error => {
            console.error('Error loading HTML:', error);
            loadingDiv.style.display = 'none';
            contentDiv.innerHTML = '<em style="color: red;">Error: ' + error.message + '</em>';
            contentDiv.style.display = 'block';
            // Allow retry by resetting the loaded flag
            details.dataset.htmlLoaded = 'false';
        });
    }

    // Use event delegation for all buttons (current and future)
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('summarize-btn')) {
            // Prevent the event from being handled multiple times
            if (e.target.disabled) {
                console.log('Button already disabled, skipping');
                return;
            }
            console.log('Handling click via delegation');
            handleButtonClick({currentTarget: e.target});
        }
    });

    // Use event delegation for details toggle to auto-load HTML
    document.addEventListener('toggle', function(e) {
        if (e.target && e.target.classList.contains('article-html-container')) {
            console.log('Details toggled for article HTML');
            handleDetailsToggle({currentTarget: e.target});
        }
    }, true);
    
    // Use MutationObserver to detect when new content is added to the DOM
    // We don't need to attach event listeners since we're using delegation
    const observer = new MutationObserver(function(mutations) {
        // Just log when new content is added that might contain our buttons
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1 && node.querySelectorAll) { // Element node
                        const buttons = node.querySelectorAll('.summarize-btn');
                        if (buttons.length > 0) {
                            console.log('Found new buttons in DOM changes');
                        }
                    }
                });
            }
        });
    });
    
    // Start observing the document with the configured parameters
    observer.observe(document.body, { childList: true, subtree: true });
}); 