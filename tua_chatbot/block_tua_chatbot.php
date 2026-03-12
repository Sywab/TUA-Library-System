<?php
// Path: D:\xampp\htdocs\moodle\blocks\tua_chatbot\block_tua_chatbot.php
class block_tua_chatbot extends block_base {
    public function init() {
        $this->title = 'TUA Assistant';
    }

    public function get_content() {
        global $OUTPUT, $PAGE;
        if ($this->content !== null) { return $this->content; }

        $this->content = new stdClass();     
        // 1. The Chat Display Area
        $html = '<div id="tua_chat_box" style="height: 250px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; background: #f9f9f9;">';
        $html .= '<div><strong>Bot:</strong> Hello! How can I help you today?</div>';
        $html .= '</div>';

        // 2. The Input Field
        $html .= '<input type="text" id="user_query" class="form-control" placeholder="Ask something...">';
        $html .= '<button onclick="send_chat()" class="btn btn-primary btn-sm mt-2 w-100">Send</button>';

        // 3. Link the JavaScript (We will create this next)
        $PAGE->requires->js('/blocks/tua_chatbot/chat.js');

        $this->content->text = $html;
        return $this->content;
    }
}