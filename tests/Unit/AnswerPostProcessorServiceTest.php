<?php

namespace Tests\Unit;

use App\Services\AI\AnswerPostProcessorService;
use PHPUnit\Framework\TestCase;

class AnswerPostProcessorServiceTest extends TestCase
{
    public function test_it_normalizes_defect_challenge_heading_without_llm_call(): void
    {
        $result = (new AnswerPostProcessorService())->process(
            '**[3] შეცილების ვადა დაფარული ნაკლის გამო მოთხოვნის შემთხვევაში**',
        );

        $this->assertStringContainsString('პრეტენზიის/შეტყობინების ვადა დაფარული ნაკლის გამო', $result['text']);
        $this->assertContains('normalized_defect_notice_terminology', $result['changes']);
    }

    public function test_it_injects_grounded_article_55_for_price_disproportion(): void
    {
        $answer = "- **Rule:** საქართველოს სამოქალაქო კოდექსი, მუხლი 54 — ბათილია ზნეობის საწინააღმდეგო გარიგება.\n"
            . '- **Apply:** მხოლოდ ფასის დისპროპორცია საკმარისი არ არის.';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'matsneResults' => [
                [
                    '_article_num' => 55,
                    'title' => 'საქართველოს სამოქალაქო კოდექსი',
                    'excerpt' => 'შესრულებასა და ანაზღაურებას შორის აშკარა შეუსაბამობა.',
                ],
            ],
        ]);

        $this->assertStringContainsString('სკ-ის 55-ე მუხლიც რელევანტურია', $result['text']);
        $this->assertContains('injected_grounded_article_55', $result['changes']);
    }

    public function test_it_softens_court_practice_claims_without_primary_authority(): void
    {
        $answer = 'სასამართლო პრაქტიკა არ აღიარებს მხოლოდ ფასის დისპროპორციას საკმარის საფუძვლად სკ-ის 54-ე მუხლისთვის.';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'finalDecisions' => [],
            'constCourtResults' => [
                ['case_number' => 'N3/7/679'],
            ],
        ]);

        $this->assertStringContainsString('პირდაპირი სასამართლო პრაქტიკა ვერ მოიძებნა', $result['text']);
        $this->assertContains('softened_non_primary_court_practice_claim', $result['changes']);
    }

    public function test_it_removes_irrelevant_article_55_when_question_has_no_disproportion_issue(): void
    {
        $answer = "- მუხლი 55 — ბათილობა საბაზრო ძალაუფლების ბოროტად გამოყენების გამო.\n"
            . "სამოქალაქო კოდექსის 55-ე მუხლი გამოიყენება მხოლოდ აშკარა დისპროპორციის შემთხვევაში.\n"
            . 'ოფციონის ჩამორთმევა დასაშვებია მხოლოდ ხელშეკრულების მკაფიო საფუძვლით.';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'userQuestion' => 'დასაქმებულის გათავისუფლება, ოფციონი, ბონუსი და პირგასამტეხლო',
        ]);

        $this->assertStringNotContainsString('55', $result['text']);
        $this->assertContains('removed_irrelevant_article_55', $result['changes']);
    }

    public function test_it_corrects_personal_data_law_not_found_claim_when_source_exists(): void
    {
        $answer = 'სპეციალური ნორმა პერსონალურ მონაცემთა დაცვის შესახებ კანონის მიხედვით არ მოიძებნა. ადმინისტრაციული ჯარიმა ავტომატურად არ გადადის თანამშრომელზე.';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'matsneResults' => [
                [
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    '_article_num' => 17,
                    'excerpt' => 'მუხლი 17. მონაცემთა უსაფრთხოება.',
                ],
            ],
        ]);

        $this->assertStringContainsString('არის სპეციალური წყარო', $result['text']);
        $this->assertStringNotContainsString('არ მოიძებნა', $result['text']);
        $this->assertContains('corrected_personal_data_law_not_found_claim', $result['changes']);
    }

    public function test_it_replaces_entire_personal_data_law_line_instead_of_partial_phrase(): void
    {
        $answer = "- **კანონი:** სპეციალური ნორმა „„პერსონალურ მონაცემთა დაცვის შესახებ“ კანონის მიხედვით არ მოიძებნა); შრომის კოდექსი, მუხლი 60\n"
            . '- **ანალიზი:** ჯარიმა ავტომატურად არ გადადის თანამშრომელზე.';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'matsneResults' => [
                [
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    '_article_num' => 17,
                    'excerpt' => 'მუხლი 17. მონაცემთა უსაფრთხოება.',
                ],
            ],
        ]);

        $this->assertStringContainsString('- **კანონი:** „პერსონალურ მონაცემთა დაცვის შესახებ“ კანონი, მუხლები 17 არის სპეციალური წყარო', $result['text']);
        $this->assertStringNotContainsString('შრომის კოდექსი, მუხლი 60', $result['text']);
        $this->assertStringNotContainsString('„„', $result['text']);
        $this->assertStringNotContainsString('არ მოიძებნა', $result['text']);
        $this->assertContains('corrected_personal_data_law_not_found_claim', $result['changes']);
    }

    public function test_it_replaces_personal_data_source_not_found_bullet_when_source_exists(): void
    {
        $answer = "**წყაროები:**\n"
            . '- სპეციალური ნორმები პერსონალურ მონაცემთა დაცვის შესახებ მოძიებულ წყაროებში არ იძებნება';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'matsneResults' => [
                [
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    '_article_num' => 17,
                    'excerpt' => 'მუხლი 17. მონაცემთა უსაფრთხოება.',
                ],
            ],
        ]);

        $this->assertStringContainsString('გამოიყენება მონაცემთა უსაფრთხოების', $result['text']);
        $this->assertStringNotContainsString('არ იძებნება', $result['text']);
        $this->assertContains('corrected_personal_data_law_not_found_claim', $result['changes']);
    }

    public function test_it_replaces_personal_data_articles_not_found_source_line_with_retrieved_articles(): void
    {
        $answer = '- **წყარო:** პერსონალურ მონაცემთა დაცვის შესახებ სპეციალური კანონი (მუხლები მოძიებული არ არის, მაგრამ მისი გამოყენება სავალდებულოა)';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'matsneResults' => [
                [
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    '_article_num' => 17,
                    'excerpt' => 'მუხლი 17. მონაცემთა უსაფრთხოება.',
                ],
                [
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    '_article_num' => 43,
                    'excerpt' => 'მუხლი 43. საზედამხედველო ღონისძიებები.',
                ],
            ],
        ]);

        $this->assertStringContainsString('- **წყარო:** „პერსონალურ მონაცემთა დაცვის შესახებ“ კანონი, მუხლები 17, 43', $result['text']);
        $this->assertStringNotContainsString('მუხლები მოძიებული არ არის', $result['text']);
        $this->assertContains('corrected_personal_data_law_not_found_claim', $result['changes']);
    }

    public function test_it_softens_personal_data_fine_only_legal_person_overstatement(): void
    {
        $answer = 'მონაცემთა გაჟონვაზე პასუხისმგებელია კომპანია. ადმინისტრაციული ჯარიმის დაკისრება შესაძლებელია მხოლოდ იურიდიულ პირზე.';

        $result = (new AnswerPostProcessorService())->process($answer, [
            'matsneResults' => [
                [
                    'title' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
                    '_article_num' => 55,
                    'excerpt' => 'მუხლი 55. ადმინისტრაციული პასუხისმგებლობა.',
                ],
            ],
        ]);

        $this->assertStringContainsString('ჯარიმის ადრესატი განისაზღვრება', $result['text']);
        $this->assertStringContainsString('ავტომატური გადაკისრება დაუშვებელია', $result['text']);
        $this->assertStringNotContainsString('მხოლოდ იურიდიულ პირზე', $result['text']);
        $this->assertContains('softened_personal_data_fine_transfer_claim', $result['changes']);
    }
}
