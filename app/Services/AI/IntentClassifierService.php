<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * მოთხოვნის კლასიფიკაცია retrieval-ის გაშვებამდე.
 *
 * intent:
 *   'search'  → სამართლებრივი ძებნა, retrieval სავალდებულო
 *   'chat'    → მისალმება, ზოგადი საუბარი, AI პირდაპირ პასუხობს
 *
 * answer_mode (classifyMode):
 *   'find'      → საქმეების მოძებნა და ჩამოთვლა
 *   'summarize' → ერთი ან რამდენიმე საქმის შეჯამება
 *   'compare'   → გადაწყვეტილებების შედარება
 *   'explain'   → სამართლებრივი საკითხის განმარტება პრაქტიკის საფუძველზე
 *   'advise'    → პრაქტიკული სამართლებრივი ორიენტაცია
 *   'chat'      → retrieval-ის გარეშე
 */
class IntentClassifierService
{
    private const CHAT_PATTERNS = [
        'გამარჯობა', 'სალამი', 'გამარჯობათ', 'hello', 'hi ', 'hey ',
        'მადლობა', 'გმადლობ', 'thanks', 'thank you',
        'ნახვამდის', 'bye', 'goodbye',
        'კარგი', 'გასაგებია', 'გავიგე', 'okay',
        'ვინ ხარ', 'რა ხარ', 'what are you', 'who are you',
        'შეგიძლია', 'can you', 'are you',
        // NOTE: 'კი', 'არა', 'ok' removed — appear inside legal questions
    ];

    private const LEGAL_SIGNALS = [
        // Decisions & courts
        'გადაწყვეტილება', 'საქმე', 'სასამართლო', 'მოსამართლე', 'case',
        'კანონი', 'კოდექსი', 'სარჩელი', 'დავა', 'დავებ', 'მოსარჩელე', 'მოპასუხე',
        'verdict', 'court', 'judge', 'law', 'legal', 'მიპოვე', 'მოძებნე',
        'შემიჯამე', 'განმარტე', 'შეადარე', 'find', 'search', 'summarize',
        'უფლება', 'ვალდებულება', 'ხელშეკრულება', 'კომპენსაცია', 'ზიანი',
        'დაკავება', 'პატიმრობა', 'სასჯელი', 'ბრალდება', 'განაჩენი',
        // Administrative / procedural
        'ადმინისტრაციულ', 'საჯარო', 'სააგენტო', 'სამინისტრო', 'ორგანო',
        'ნებართვ', 'ლიცენზი', 'რეგისტრაცი', 'დასკვნ', 'გადაწყვეტ',
        'წვდომა', 'საიდუმლო', 'ინფორმაცი', 'გამჟღავნება', 'კონფიდენციალ',
        'ბინადრობ', 'მოქალაქეობ', 'მიგრაცი', 'ვიზა', 'ლტოლვილ',
        'გასაჩივრება', 'გამოთხოვ', 'შეზღუდვ', 'უარყოფ', 'გაუქმება',
        // Rights & disputes
        'თანასწორობ', 'დისკრიმინაცი', 'სამართლიანი', 'პრინციპი',
        'კანონიერ', 'არაკანონიერ', 'მართლზომიერ', 'ბათილ',
        // ECHR
        'echr', 'სტრასბურგ', 'კონვენცი', 'ადამიანის უფლება',
    ];

    // answer_mode signals — კლასიფიცირება კითხვის ტიპის მიხედვით
    private const SUMMARIZE_SIGNALS = [
        'შემიჯამე', 'summarize', 'მოკლედ', 'ძირითადი', 'შინაარსი',
        'რაზეა', 'არსი', 'რა გადაწყდა', 'შეჯამება', 'overview',
    ];

    private const COMPARE_SIGNALS = [
        'შეადარე', 'compare', 'განსხვავება', 'მსგავსება', 'ორივე',
        'რომელი უფრო', 'ვს', 'vs', 'პირველი და მეორე', 'ორ საქმ',
    ];

    private const EXPLAIN_SIGNALS = [
        'განმარტე', 'explain', 'რას ნიშნავს', 'რა არის', 'what is',
        'განსაზღვრე', 'define', 'გამიხსენი', 'ახსენი', 'clarify',
        'რა პრინციპი', 'რომელი ნორმა', 'სამართლებრივი საფუძველი',
    ];

    private const ADVISE_SIGNALS = [
        'რა უნდა ვქნა', 'what should i', 'რჩევა', 'advice', 'counsel',
        'შემიძლია', 'can i', 'უფლება მაქვს', 'am i allowed',
        'როგორ', 'how to', 'how do i', 'what are my options',
        'ვიჩივლო', 'გავასაჩივრო', 'დავიცვა', 'protect',
    ];

    private const FIND_SIGNALS = [
        'მიპოვე', 'მოძებნე', 'find', 'search', 'ნახე', 'look for',
        'გამომიგზავნე', 'show me', 'მაჩვენე', 'list', 'ჩამოთვალე',
        'მოიძიე', 'გამომიძახე',
    ];

    /**
     * Returns 'search' or 'chat' — top-level intent.
     */
    public function classify(string $message): string
    {
        $lower = mb_strtolower(trim($message));
        $len   = mb_strlen($lower);

        if ($len < 15) {
            foreach (self::LEGAL_SIGNALS as $sig) {
                if (str_contains($lower, $sig)) return 'search';
            }
            return 'chat';
        }

        foreach (self::LEGAL_SIGNALS as $sig) {
            if (str_contains($lower, $sig)) return 'search';
        }

        foreach (self::CHAT_PATTERNS as $pattern) {
            if (str_contains($lower, mb_strtolower($pattern))) return 'chat';
        }

        return 'search';
    }

    /**
     * Returns granular answer mode for use by the answer generator.
     * Call after classify() returns 'search'.
     *
     * Modes: find | summarize | compare | explain | advise | chat
     */
    public function classifyMode(string $message): string
    {
        $lower = mb_strtolower(trim($message));

        // Order matters: most specific first
        foreach (self::COMPARE_SIGNALS as $sig) {
            if (str_contains($lower, $sig)) return 'compare';
        }
        foreach (self::SUMMARIZE_SIGNALS as $sig) {
            if (str_contains($lower, $sig)) return 'summarize';
        }
        foreach (self::ADVISE_SIGNALS as $sig) {
            if (str_contains($lower, $sig)) return 'advise';
        }
        foreach (self::EXPLAIN_SIGNALS as $sig) {
            if (str_contains($lower, $sig)) return 'explain';
        }
        foreach (self::FIND_SIGNALS as $sig) {
            if (str_contains($lower, $sig)) return 'find';
        }

        // Default for search intent: explain (requires reasoning, not just listing)
        return 'explain';
    }
}
