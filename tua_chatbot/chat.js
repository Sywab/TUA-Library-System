function send_chat() {
    var input = document.getElementById('user_query');
    var chatBox = document.getElementById('tua_chat_box');
    var query = input.value;

    if (query == "") return;
    // 1. Display User Message
    chatBox.innerHTML += '<div><strong>You:</strong> ' + query + '</div>';
    input.value = ""; // Clear input

    // 2. Send to Moodle via AJAX
    // Using the absolute path that worked in your browser test
    fetch('/blocks/tua_chatbot/process_chat.php?message=' + encodeURIComponent(query))
        .then(response => {
            if (!response.ok) {
                // This will tell you if it hits a 404 or 500 error
                throw new Error('Server error: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            // 3. Display Bot Response
            chatBox.innerHTML += '<div><strong>Bot:</strong> ' + data.reply + '</div>';
            chatBox.scrollTop = chatBox.scrollHeight; // Auto-scroll
        })
        .catch(error => {
            // 4. Handle Errors (like 404 or syntax errors)
            chatBox.innerHTML += '<div style="color:red;"><strong>System:</strong> ' + error.message + '</div>';
            console.error('Chat Error:', error);
        });
}