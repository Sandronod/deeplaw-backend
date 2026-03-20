<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * მოთხოვნის კლასიფიკაცია retrieval-ის გაშვებამდე.
 *
 * 'search'  → სამართლებრივი ძებნა, retrieval სავალდებულო
 * 'chat'    → მისალმება, ზოგადი საუბარი, AI პირდაპირ პასუხობს ბაზის გარეშე
 */
class IntentClassifierService
{
    // სწრაფი rule-based შემოწმება — OpenAI call-ის გარეშე
    private const CHAT_PATTERNS = [
        // მისალმებები
        'გამარჯობა', 'სალამი', 'გამარჯობათ', 'hello', 'hi ', 'hey ',
        // მადლობა
        'მადლობა', 'გმადლობ', 'thanks', 'thank you',
        // დამშვიდობება
        'ნახვამდის', 'bye', 'goodbye',
        // დადასტურება / მოკლე რეაქცია
        'კარგი', 'გასაგებია', 'გავიგე', 'ok', 'okay', 'კი', 'არა',
        // კითხვები AI-ს შესახებ
        'ვინ ხარ', 'რა ხარ', 'what are you', 'who are you',
        'შეგიძლია', 'can you', 'are you',
    ];

    // ეს სიტყვები = სავარაუდოდ სამართლებრივი ძებნა
    private const LEGAL_SIGNALS = [
        'გადაწყვეტილება', 'საქმე', 'სასამართლო', 'მოსამართლე', 'case',
        'კანონი', 'კოდექსი', 'სარჩელი', 'დავა', 'მოსარჩელე', 'მოპასუხე',
        'verdict', 'court', 'judge', 'law', 'legal', 'მიპოვე', 'მოძებნე',
        'შემიჯამე', 'განმარტე', 'შეადარე', 'find', 'search', 'summarize',
        'უფლება', 'ვალდებულება', 'ხელშეკრულება', 'კომპენსაცია', 'ზიანი',
        'დაკავება', 'პატიმრობა', 'სასჯელი', 'ბრალდება', 'განაჩენი',
    ];

    public function classify(string $message): string
    {
        $lower = mb_strtolower(trim($message));
        $len   = mb_strlen($lower);

        // ძალიან მოკლე შეტყობინება (< 15 სიმბოლო) — სავარაუდოდ chat
        if ($len < 15) {
            // თუ legal signal-ია მოკლე ტექსტშიც კი — search
            foreach (self::LEGAL_SIGNALS as $sig) {
                if (str_contains($lower, $sig)) {
                    return 'search';
                }
            }
            return 'chat';
        }

        // Legal signal-ების შემოწმება (პრიორიტეტი)
        foreach (self::LEGAL_SIGNALS as $sig) {
            if (str_contains($lower, $sig)) {
                return 'search';
            }
        }

        // Chat pattern-ების შემოწმება
        foreach (self::CHAT_PATTERNS as $pattern) {
            if (str_contains($lower, mb_strtolower($pattern))) {
                return 'chat';
            }
        }

        // Default: search (უსაფრთხო default — ბაზაში ვეძებთ)
        return 'search';
    }
}
