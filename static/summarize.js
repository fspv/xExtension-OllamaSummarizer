document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('summarize-btn')) {
            const button = e.target;
            const entryId = button.dataset.entryId;
            
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
    });
}); 