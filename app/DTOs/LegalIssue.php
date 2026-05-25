<?php

namespace App\DTOs;

readonly class LegalIssue
{
    public function __construct(
        public string $title,
        public string $domain,    // civil|criminal|admin|corporate|labor|property|procedure|tax
        public int    $priority,  // 1 = ყველაზე კრიტიკული
        public array  $keywords,  // retrieval hints
    ) {}

    public function domainLabel(): string
    {
        return match ($this->domain) {
            'civil'       => 'სამოქალაქო',
            'criminal'    => 'სისხლის სამართალი',
            'admin'       => 'ადმინისტრაციული',
            'corporate'   => 'კორპორაციული',
            'labor'       => 'შრომის სამართალი',
            'property'    => 'სანივთო სამართალი',
            'procedure'   => 'საპროცესო',
            'tax'         => 'საგადასახადო',
            'family'      => 'საოჯახო',
            'echr'        => 'ადამიანის უფლებები',
            default       => $this->domain,
        };
    }

    public function toArray(): array
    {
        return [
            'title'    => $this->title,
            'domain'   => $this->domain,
            'priority' => $this->priority,
            'keywords' => $this->keywords,
        ];
    }
}
