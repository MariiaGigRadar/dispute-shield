<?php
function tg(string $msg): void {
    if (!TELEGRAM_BOT_TOKEN || !TELEGRAM_CHAT_ID) return;
    @file_get_contents(
        'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage?' .
        http_build_query(['chat_id' => TELEGRAM_CHAT_ID, 'text' => $msg])
    );
}
