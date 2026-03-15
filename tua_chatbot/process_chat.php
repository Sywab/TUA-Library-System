<?php
require_once('../../config.php');
require_login();

// 1. Get the user message
$user_message = optional_param('message', '', PARAM_TEXT);

if (empty($user_message)) {
    echo json_encode(['reply' => 'Please type something!']);
    exit;
}

// 2. Get Student Name from Moodle
global $USER;
$student_name = $USER->firstname;

// 3. API Key and Gemini 3 Flash Model Endpoint

// Updated to Gemini 3 Flash
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key=" . $api_key;

// 4. PREPARE THE DATA
$data = [
    "system_instruction" => [
        "parts" => [
            ["text" => "
                Role: Provide short, direct, and concise answers about Trinity University of Asia (TUA).
                You are the TUA Assistant, a friendly and professional AI for Trinity University of Asia (TUA).
                
                UNIVERSITY PROFILE:
                - Location: 275 E. Rodriguez Sr. Ave., Cathedral Heights, Quezon City, Philippines.
                - Motto: Pro Deo et Patria (For God and Country).
                - Colors: Green and White.
                - Nickname: White Stallions.
                
                MISSION: To promote the formation of integrally-developed, competent, productive and socially responsible citizens by instilling Christian values.
                VISION: A premier Christian University in Asia and the Pacific transforming a community of learners as leaders towards a humane society.
                CORE VALUES: Integrity, Excellence, Innovation, Teamwork, and Social Responsibility.
                
                PERSONA: You are 'TUA-Bot', the official AI of Trinity University of Asia powered by Gemini 3 Flash.
                
                RULES:
                1. Always greet the student warmly. Greet $student_name briefly (e.g., 'Hi $student_name!').
                2. If a student asks about grades, tell them to check the 'TUA Portal'.
                3. Use Markdown for formatting: Use **bold** for emphasis and bullets for lists.
                4. If the question is not about TUA, politely steer them back to school topics.
                5. Use a maximum of 2-3 sentences per response.
                6. Use bullet points only if listing more than 2 items.
                7. No long introductions or unnecessary fluff.
            "]
        ]
    ],
    "contents" => [
        [
            "parts" => [
                ["text" => $user_message]
            ]
        ]
    ],
    "generationConfig" => [
        "temperature" => 0.5,
        "maxOutputTokens" => 800,
    ]
];

// 5. THE cURL REQUEST
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

// 6. EXECUTE
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['reply' => 'Connection Error: ' . $err]);
} else {
    $result = json_decode($response, true);

    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $ai_reply = $result['candidates'][0]['content']['parts'][0]['text'];
        echo json_encode(['reply' => $ai_reply]);
    } elseif (isset($result['error'])) {
        echo json_encode(['reply' => 'Gemini 3 Error: ' . $result['error']['message']]);
    } else {
        echo json_encode(['reply' => 'The AI is currently unavailable. Please try again later.']);
    }
}