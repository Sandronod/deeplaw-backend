<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Legal Issue -> Norm Map
    |--------------------------------------------------------------------------
    |
    | Curated routing map for deterministic legal norm coverage. Each issue
    | connects question signals to the minimum statutes/articles the system
    | should try to retrieve before asking the model to write a legal answer.
    |
    | This is intentionally config-backed for the first phase. Once the map is
    | stable, the same shape can be moved to a DB-backed registry with review
    | status, versioning, and failure-mining metadata.
    |
    */
    'issues' => [
        'civil_procedure.magistrate_claim_value' => [
            'enabled' => true,
            'domain' => 'civil_procedure',
            'domains' => ['civil_procedure', 'procedure'],
            'title_ka' => 'მაგისტრატი მოსამართლის საგნობრივი განსჯადობა სარჩელის ფასით',
            'trigger_all_groups' => [
                ['მაგისტრატ', 'განსჯად', 'საგნობრივ', 'კომპეტენც'],
                ['სარჩელის ფას', 'ფასია', 'ღირებულ', '50 000', '50000', 'ლარ', '₾'],
            ],
            'required_sources' => [
                ['law' => 'civil_procedure_code', 'articles' => [9]],
            ],
            'must_discuss' => [
                'ზღვარი ფასდება სარჩელის ფასით.',
                'ზუსტად 50 000 ლარი არ ნიშნავს ზღვრის გადაცილებას, თუ ნორმა ითვალისწინებს 50 000-მდე/ჩათვლით კომპეტენციას.',
            ],
            'forbidden_shortcuts' => [
                'არ აურიო მაგისტრატის განსჯადობა სააპელაციო საჩივრის დასაშვებობის ზღვართან.',
                'არ გამოიყენო სსკ 365-ე მუხლი სარჩელის ფასით მაგისტრატის კომპეტენციის გადასაწყვეტად.',
            ],
            'boundary_rule' => 'equal_included',
            'priority' => 100,
        ],

        'civil_procedure.counterclaim_subject_matter' => [
            'enabled' => true,
            'domain' => 'civil_procedure',
            'domains' => ['civil_procedure', 'procedure'],
            'title_ka' => 'შეგებებული სარჩელი და საგნობრივი განსჯადობა',
            'trigger_any_keywords' => ['შეგებებულ', 'შემხვედრ სარჩელ'],
            'required_sources' => [
                ['law' => 'civil_procedure_code', 'articles' => [188, 21]],
            ],
            'must_discuss' => [
                'შეგებებული სარჩელის შეტანა არ ხსნის საგნობრივი განსჯადობის მოთხოვნას.',
                'საგნობრივი განსჯადობა არ იქმნება მხარეთა შეთანხმებით.',
            ],
            'forbidden_shortcuts' => [
                'არ თქვა, რომ ერთი საქმის ფარგლებში შეტანა ავტომატურად აქცევს მოთხოვნას იმავე სასამართლოს განსჯადად.',
            ],
            'priority' => 95,
        ],

        'property.real_estate_registration' => [
            'enabled' => true,
            'domain' => 'property',
            'domains' => ['civil', 'civil_law', 'property'],
            'title_ka' => 'უძრავი ნივთის საკუთრება, რეგისტრაცია და საჯარო რეესტრის ნდობა',
            'trigger_any_keywords' => [
                'საჯარო რეესტრ',
                'დაურეგისტრირ',
                'რეგისტრაცია დასრულებული',
                'წინასწარი ნასყიდ',
                'უძრავ ნივთ',
                'უძრავი ქონებ',
                'ბინის საკუთრ',
                'საკუთრების რეგისტრ',
            ],
            'required_sources' => [
                ['law' => 'civil_code', 'articles' => [183, 185, 311, 312, 323]],
            ],
            'optional_sources' => [
                ['law' => 'civil_code', 'articles' => [214]],
            ],
            'must_discuss' => [
                'უძრავ ნივთზე საკუთრების შეძენა უკავშირდება საჯარო რეესტრში რეგისტრაციას.',
                'სრულად გადახდილი ფასი ჩვეულებრივ ქმნის ვალდებულებით მოთხოვნას, მაგრამ ავტომატურად არა რეგისტრირებულ საკუთრებას.',
                'რეესტრის სისწორის პრეზუმფცია და კეთილსინდისიერება ცალკე უნდა შეფასდეს.',
            ],
            'forbidden_shortcuts' => [
                'არ თქვა მოკლედ: გადახდილია, ამიტომ მესაკუთრეა.',
                'არ თქვა მოკლედ: დაურეგისტრირებელია, ამიტომ საერთოდ არ აქვს ქონებრივი უფლება.',
            ],
            'priority' => 96,
        ],

        'property.mortgage_priority_enforcement' => [
            'enabled' => true,
            'domain' => 'property',
            'domains' => ['civil', 'civil_law', 'property', 'corporate'],
            'title_ka' => 'იპოთეკის პრიორიტეტი და იპოთეკით დატვირთული ნივთის რეალიზაცია',
            'trigger_any_keywords' => [
                'იპოთეკ',
                'იპოთეკარ',
                'უზრუნველყოფის უფლება',
                'ქონების რეალიზაცი',
                'ბანკის სასარგებლოდ დაიტვირთ',
            ],
            'required_sources' => [
                ['law' => 'civil_code', 'articles' => [286, 287, 290, 297, 300, 301, 302]],
            ],
            'must_discuss' => [
                'იპოთეკის პრიორიტეტი და რიგითობა უკავშირდება რეგისტრაციას და კონკრეტული უფლების წარმოშობის დროს.',
                'რეალიზაციის უფლება ცალკე უნდა შეფასდეს იპოთეკის ხელშეკრულებითა და კანონით.',
            ],
            'forbidden_shortcuts' => [
                'არ თქვა, რომ ბანკი ყოველთვის ავტომატურად სჯობს ყველა მყიდველს კონკრეტული რეგისტრაციის/პრიორიტეტის შემოწმების გარეშე.',
            ],
            'priority' => 94,
        ],

        'insolvency.creditor_status' => [
            'enabled' => true,
            'domain' => 'insolvency',
            'domains' => ['insolvency', 'bankruptcy', 'corporate', 'civil'],
            'title_ka' => 'გადახდისუუნარობა და კრედიტორული მოთხოვნის სტატუსი',
            'trigger_any_keywords' => [
                'გადახდისუუნარ',
                'გაკოტრ',
                'კრედიტორთა რეესტრ',
                'კრედიტორის სტატუს',
                'კრედიტორების სტატუს',
                'ჩვეულებრივი კრედიტორ',
            ],
            'required_sources' => [
                ['law' => 'insolvency_law', 'articles' => [1, 3, 5, 6, 52]],
            ],
            'must_discuss' => [
                'მოთხოვნა უნდა განისაზღვროს როგორც უზრუნველყოფილი, არაუზრუნველყოფილი, სანივთო, ვალდებულებითი ან ზიანის მოთხოვნა.',
                'კრედიტორთა რეესტრში მოთხოვნის წარდგენა და სტატუსი ცალკე საპროცესო საკითხია.',
            ],
            'forbidden_shortcuts' => [
                'არ დაასახელო ყველა მყიდველი ავტომატურად ჩვეულებრივ კრედიტორად ინდივიდუალური უფლების სტატუსის შემოწმების გარეშე.',
            ],
            'priority' => 92,
        ],

        'family.inheritance_estate_claims' => [
            'enabled' => true,
            'domain' => 'family',
            'domains' => ['family', 'civil', 'civil_law', 'property'],
            'title_ka' => 'სამკვიდრო მასა და დაურეგისტრირებელი ქონებრივი მოთხოვნები',
            'trigger_any_keywords' => ['მემკვიდრ', 'სამკვიდრ', 'გარდაიცვალ'],
            'required_sources' => [
                ['law' => 'civil_code', 'articles' => [1306, 1307, 1319, 1320, 1328, 1336, 1339]],
            ],
            'must_discuss' => [
                'სამკვიდროში შეიძლება შეფასდეს არა მხოლოდ რეგისტრირებული საკუთრება, არამედ ქონებრივი მოთხოვნის უფლებაც.',
                'კანონით მემკვიდრეთა რიგი და ცოცხლად დარჩენილი მეუღლის სპეციალური სტატუსი ცალკე უნდა გაიმიჯნოს.',
            ],
            'forbidden_shortcuts' => [
                'არ თქვა მხოლოდ: დაურეგისტრირებელი ბინა სამკვიდროში არ შედის; ჯერ შეაფასე მოთხოვნის უფლების მემკვიდრეობითობა.',
            ],
            'priority' => 90,
        ],

        'family.marital_property_share' => [
            'enabled' => true,
            'domain' => 'family',
            'domains' => ['family', 'civil', 'civil_law', 'property'],
            'title_ka' => 'მეუღლეთა თანასაკუთრება და ქორწინებაში შეძენილი ქონება',
            'trigger_any_keywords' => [
                'მეუღლეთა საერთო',
                'მეუღლეთა თანასაკუთრ',
                'ქორწინების განმავლობაში შეძენ',
                'ერთობლივი საკუთრ',
                'მეუღლე აცხადებს',
                'მეუღლეთა ქონ',
            ],
            'required_sources' => [
                ['law' => 'civil_code', 'articles' => [1158, 1160, 1161, 1163, 1171]],
            ],
            'must_discuss' => [
                'ქორწინებაში შეძენის დრო, თანხის წყარო და რეგისტრაციის/მოთხოვნის უფლების სტატუსი ცალკე უნდა შეფასდეს.',
                'მეუღლის თანასაკუთრების წილი არ უნდა აირიოს მემკვიდრეობის წილთან.',
            ],
            'priority' => 88,
        ],

        'privacy.personal_data_incident' => [
            'enabled' => true,
            'domain' => 'privacy',
            'domains' => ['privacy', 'administrative', 'admin', 'labor', 'civil'],
            'title_ka' => 'პერსონალური მონაცემების უსაფრთხოების ინციდენტი',
            'trigger_any_keywords' => [
                'პერსონალურ მონაცემ',
                'მონაცემთა გაჟონ',
                'მონაცემთა დაცვ',
                'api',
                'მომხმარებელთა მონაცემ',
            ],
            'required_sources' => [
                ['law' => 'personal_data_law', 'articles' => [2, 16, 17, 39, 43, 55]],
            ],
            'must_discuss' => [
                'დამმუშავებლის/უფლებამოსილი პირის როლები, უსაფრთხოების ვალდებულება და საზედამხედველო ღონისძიებები ცალკე უნდა შეფასდეს.',
                'ადმინისტრაციული სანქცია და თანამშრომლის რეგრესული/შრომითი პასუხისმგებლობა არ არის ერთი და იგივე.',
            ],
            'forbidden_shortcuts' => [
                'არ დატოვო პერსონალური მონაცემების საკითხი მხოლოდ შრომის ან სამოქალაქო კოდექსზე.',
            ],
            'priority' => 92,
        ],

        'admin.judicial_review' => [
            'enabled' => true,
            'domain' => 'administrative',
            'domains' => ['admin', 'administrative', 'privacy'],
            'title_ka' => 'ადმინისტრაციული აქტის გასაჩივრება და სასამართლო კონტროლი',
            'trigger_any_keywords' => [
                'ადმინისტრაციული წარმოება',
                'ადმინისტრაციული გადაწყვეტილება',
                'ადმინისტრაციული აქტი',
                'ადმინისტრაციული ჯარიმა',
                'სასამართლო კონტროლ',
                'გასაჩივრ',
                'მონაცემთა დაცვის სამსახურ',
            ],
            'require_domain_match' => true,
            'required_sources' => [
                ['law' => 'admin_procedure_code', 'articles' => [22, 24, 32, 34]],
                ['law' => 'general_admin_code', 'articles' => [60, 96]],
            ],
            'must_discuss' => [
                'შეამოწმე კომპეტენცია, პროცედურა, დასაბუთება და პროპორციულობა.',
            ],
            'priority' => 86,
        ],

        'labor.termination_procedure' => [
            'enabled' => true,
            'domain' => 'labor',
            'domains' => ['labor'],
            'title_ka' => 'შრომითი ურთიერთობის შეწყვეტა და პროცედურული გარანტიები',
            'trigger_any_keywords' => ['გათავისუფლ', 'გაათავისუფლ', 'შეწყვეტ', 'დისციპლინ', 'დასაბუთ', 'მოშლ'],
            'required_sources' => [
                ['law' => 'labor_code', 'articles' => [47, 48]],
            ],
            'must_discuss' => [
                'გათავისუფლების საფუძველი და პროცედურული გარანტიები ცალკე უნდა შეფასდეს.',
                'დასაქმებულის განმარტების/მოსმენის უფლება და წერილობითი დასაბუთება მნიშვნელოვანია.',
            ],
            'priority' => 84,
        ],

        'labor.non_compete' => [
            'enabled' => true,
            'domain' => 'labor',
            'domains' => ['labor'],
            'title_ka' => 'არაკონკურენციის შეზღუდვა',
            'trigger_any_keywords' => ['არაკონკურენც', 'კონკურენტ კომპანი', 'კონკურენტი დამსაქმებელ', 'შეთავსებით სამუშაო'],
            'required_sources' => [
                ['law' => 'labor_code', 'articles' => [16, 46, 60]],
            ],
            'must_discuss' => [
                'შეამოწმე ვადა, სფერო, ტერიტორია, კომპენსაცია და შეზღუდვის პროპორციულობა.',
            ],
            'priority' => 82,
        ],

        'labor.material_liability' => [
            'enabled' => true,
            'domain' => 'labor',
            'domains' => ['labor'],
            'title_ka' => 'დასაქმებულის მატერიალური პასუხისმგებლობა',
            'trigger_any_keywords' => ['მატერიალურ პასუხისმგებლ', 'ბრალეულ', 'მიზეზობრივ', 'ზიან'],
            'require_domain_match' => true,
            'required_sources' => [
                ['law' => 'labor_code', 'articles' => [44]],
            ],
            'must_discuss' => [
                'შრომით ურთიერთობაში ზიანის მოთხოვნა შეაფასე შრომის კოდექსის მატერიალური პასუხისმგებლობის ჩარჩოთი.',
            ],
            'priority' => 80,
        ],

        'civil.penalty_reduction' => [
            'enabled' => true,
            'domain' => 'civil',
            'domains' => ['civil', 'civil_law'],
            'title_ka' => 'პირგასამტეხლო და სასამართლოს მიერ შემცირება',
            'trigger_any_keywords' => ['პირგასამტეხლ', 'contractual penalty'],
            'required_sources' => [
                ['law' => 'civil_code', 'articles' => [417, 420]],
            ],
            'must_discuss' => [
                'პირგასამტეხლოს შემცირების საკითხი არ უნდა ჩანაცვლდეს სამოქალაქო კოდექსის 55-ე მუხლით.',
            ],
            'forbidden_shortcuts' => [
                'არ გამოიყენო სკ 55 პირგასამტეხლოს შემცირების უნივერსალურ საფუძვლად.',
            ],
            'priority' => 80,
        ],

        'civil.damages_causation' => [
            'enabled' => true,
            'domain' => 'civil',
            'domains' => ['civil', 'civil_law', 'labor', 'property'],
            'title_ka' => 'ზიანი, ბრალი, მიზეზობრივი კავშირი და ზიანის ოდენობა',
            'trigger_any_keywords' => ['ზიან', 'ანაზღაურებ', 'მიზეზობრივ', 'უშუალო შედეგ', 'მორალურ', 'რეპუტაციულ'],
            'required_sources' => [
                ['law' => 'civil_code', 'articles' => [408, 412, 413, 415]],
            ],
            'must_discuss' => [
                'ზიანზე საჭიროა დარღვევა, ბრალი, მიზეზობრივი კავშირი, ზიანის ოდენობა და მტკიცების ტვირთი.',
            ],
            'priority' => 78,
        ],

        'civil_procedure.criminal_preclusion' => [
            'enabled' => true,
            'domain' => 'civil_procedure',
            'domains' => ['civil_procedure', 'procedure', 'criminal', 'civil'],
            'title_ka' => 'სისხლის სამართლის განაჩენის პრეიუდიციული მნიშვნელობა სამოქალაქო დავაში',
            'trigger_any_keywords' => ['თაღლით', 'სისხლის სამართლის', 'განაჩენ', 'პრეიუდიც'],
            'required_sources' => [
                ['law' => 'civil_procedure_code', 'articles' => [106]],
            ],
            'must_discuss' => [
                'განაჩენის პრეიუდიციულობა არ წყვეტს ავტომატურად ზიანის ოდენობას ან ყველა სამოქალაქო წინაპირობას.',
            ],
            'priority' => 76,
        ],

        'civil_procedure.proof_burden' => [
            'enabled' => true,
            'domain' => 'civil_procedure',
            'domains' => ['civil_procedure', 'procedure', 'civil', 'property'],
            'title_ka' => 'მტკიცების ტვირთი',
            'trigger_any_keywords' => ['მტკიცების ტვირთ', 'ვინ უნდა დაამტკიც', 'კეთილსინდისიერება დაამტკიც', 'ბანკის ცოდნა'],
            'required_sources' => [
                ['law' => 'civil_procedure_code', 'articles' => [102]],
            ],
            'must_discuss' => [
                'გამიჯნე, ვინ ამტკიცებს კეთილსინდისიერებას, ცოდნას, ზიანს და მიზეზობრივ კავშირს.',
            ],
            'priority' => 74,
        ],

        'civil_procedure.joinder' => [
            'enabled' => true,
            'domain' => 'civil_procedure',
            'domains' => ['civil_procedure', 'procedure', 'civil', 'property'],
            'title_ka' => 'რამდენიმე მოსარჩელე და საპროცესო თანამონაწილეობა',
            'trigger_any_keywords' => ['კოლექტიური სარჩელ', 'ერთობლივი სარჩელ', 'რამდენიმე მოსარჩელ', '240 მყიდველ', 'ერთად სარჩელ'],
            'required_sources' => [
                ['law' => 'civil_procedure_code', 'articles' => [86]],
            ],
            'must_discuss' => [
                'ერთიანი განხილვის შესაძლებლობა დამოკიდებულია საერთო საფუძველზე და ინდივიდუალური ფაქტების მოცულობაზე.',
            ],
            'priority' => 72,
        ],
    ],
];
