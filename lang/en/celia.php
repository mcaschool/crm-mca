<?php

declare(strict_types=1);

// Celia's widget-facing strings. Behavior BARRIERS live in config/crm.php
// (system_prompt), not here: this is interface only.

return [
    'greeting' => "Hi :name, I'm :advisor, the intelligent academic advisor for MCA School's Microcredential programs. How can I help you?",
    'greeting_language_note' => 'Programs are currently taught in Spanish only, but I can guide you in English.',
    'context_viewed_programs' => 'I saw you were interested in: :programs.',
    'context_topics' => 'You were looking into :topics.',

    // Natural, contextual transition (only when the tree DOES cover the topic).
    'route_to_buttons' => 'I can help you with that. Which of these topics would you like?',

    'near_limit' => 'We can go a bit further, but so I do not keep you waiting: the fine detail (price, syllabus, dates) is in the catalog, and you can enroll whenever you like. Shall we continue?',
    'limit_reached' => 'We have talked quite a bit and I do not want to keep you waiting. Each program\'s detail is in the catalog, and enrollment is open whenever you decide. If you prefer, leave your question and we will pick it up later. Catalog: :catalog',

    'ai_unavailable' => 'I cannot chat in detail right now, but I can guide you with the options or point you to the catalog: :catalog',

    // Advisors with their own identity and instructions (not Celia): neutral texts.
    'greeting_custom' => "Hi :name, I'm :advisor, :role. How can I help you?",
    'default_role' => "the institution's virtual assistant",
    'limit_reached_custom' => "We've talked quite a bit and I don't want to keep you waiting. If you need more detail, the institution's team can help you.",
    'ai_unavailable_custom' => 'I cannot answer in detail right now. Please try again in a few minutes.',
];
