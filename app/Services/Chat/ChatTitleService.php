<?php

namespace App\Services\Chat;

class ChatTitleService
{
    /**
     * Generates a short chat title from the first user message.
     */
    public function generateFromMessage(string $message): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $message));

        if (mb_strlen($clean) <= 60) {
            return $clean;
        }

        // Cut at last word boundary before 60 chars
        $short = mb_substr($clean, 0, 60);
        $pos   = mb_strrpos($short, ' ');

        return ($pos !== false ? mb_substr($short, 0, $pos) : $short) . '…';
    }
}
