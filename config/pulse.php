<?php

/*
|--------------------------------------------------------------------------
| Department Pulse survey
|--------------------------------------------------------------------------
|
| The survey content, options and validation vocabulary in one place. The
| public GET /pulse/config endpoint serves this file to the form, and the
| backend uses the same lists to validate submissions, so the two can never
| drift apart. Department keys are stable: responses store the key, not the
| display name, so renaming a department is safe.
|
*/

return [

    'departments' => [
        'noc' => [
            'name' => 'NOC – Monitoring',
            'blurb' => 'Site, odds, feeds and transactions, 24/7',
            'duties' => 'e.g. watch site/app uptime, odds feeds, live betting, payments and suspicious activity; raise alerts and incidents.',
            'problems' => 'Examples: site or app slow or down, odds or live feed delays, markets not suspending or reopening, payment gateway alerts, suspicious betting patterns, alerts that nobody picks up.',
            'escalation' => 'e.g. Monitor spots issue → shift lead → IT on-call → Head of Ops; customers informed via call center?',
            'surprise' => 'e.g. a release changed how bets settle and no one told the monitoring shift.',
            'watch' => 'e.g. error rates, failed deposits, settlement delays, sudden traffic drops after a release.',
        ],
        'it' => [
            'name' => 'IT',
            'blurb' => 'Platform, releases, integrations',
            'duties' => 'e.g. platform releases, integrations with providers and payment gateways, maintenance, support tickets.',
            'problems' => 'Examples: requests arriving through too many channels, unclear priorities, incidents reported without detail, releases blocked by late approvals.',
            'escalation' => 'e.g. ticket or call → on-call engineer → IT lead → provider; who signs off a hotfix?',
            'surprise' => 'e.g. a change that other teams were not ready for, and what it caused.',
            'watch' => 'What should the other teams monitor after your releases?',
        ],
        'callcenter' => [
            'name' => 'Call Center',
            'blurb' => 'Customer calls, chats and WhatsApp',
            'duties' => 'e.g. answer customer calls, chats and WhatsApp; resolve account, deposit, withdrawal and bet queries.',
            'problems' => 'Examples: deposits not reflecting, delayed withdrawals, bonus or promo questions agents were not briefed on, verification issues, outages customers report before agents know.',
            'escalation' => 'e.g. agent → team leader → payments/IT → back to customer; how long does a customer wait?',
            'surprise' => 'e.g. a promo or feature launched and customers called before agents had any information.',
            'watch' => 'e.g. spikes in calls about a feature, new error messages customers see.',
        ],
        'marketing' => [
            'name' => 'Marketing',
            'blurb' => 'Social, promos, influencers, content',
            'duties' => 'e.g. social pages, promotions, campaigns, influencers, SMS and push messages.',
            'problems' => 'Examples: promo terms changing late, features launched without assets, outages during a campaign, call center or IT not briefed on a promo.',
            'escalation' => 'e.g. who approves a promo, who must be told before it goes live, who handles a post with a wrong bonus?',
            'surprise' => 'e.g. a site change broke a campaign link or bonus during a live promo.',
            'watch' => 'e.g. promo codes working, landing pages, bonus crediting, social comments about issues.',
        ],
        'finance' => [
            'name' => 'Finance',
            'blurb' => 'Reconciliations, payouts, reporting',
            'duties' => 'e.g. reconciliations, payout approvals, tax and regulator reporting, budgets.',
            'problems' => 'Examples: reconciliation mismatches, big wins needing urgent approval, reports pulled with different numbers, bonus costs not known in advance.',
            'escalation' => 'e.g. mismatch found → payments team → IT → finance manager → management.',
            'surprise' => 'e.g. a change in bonus or settlement logic that changed the numbers without notice.',
            'watch' => 'e.g. daily GGR, payout totals, bonus cost, deposits vs. withdrawals.',
        ],
        'payments' => [
            'name' => 'Payments',
            'blurb' => 'Deposits, withdrawals, mobile money',
            'duties' => 'e.g. deposits and withdrawals, mobile money and bank channels, failed or pending transactions.',
            'problems' => 'Examples: mobile money provider downtime, pending or failed deposits, withdrawal backlogs, refunds, providers changing APIs or limits.',
            'escalation' => 'e.g. failed transaction → payments officer → provider → IT; who tells the call center?',
            'surprise' => 'e.g. a provider outage or limit change your team heard about from customers first.',
            'watch' => 'e.g. success rates per provider, pending queue size, reversals.',
        ],
    ],

    'options' => [
        'markets' => [
            'Castlebet Zambia',
            'Castlebet Namibia',
            'Elitebet Congo-Brazzaville',
        ],
        'shift' => [
            'Office hours',
            'Shifts, 24/7',
            'Shifts, extended hours',
            'Mixed',
        ],
        'tools' => [
            'Back office / admin panel',
            'Monitoring dashboards',
            'Ticketing system',
            'WhatsApp groups',
            'Email',
            'Microsoft Teams',
            'Slack',
            'Phone calls',
            'Excel / Google Sheets',
            'CRM',
            'Mobile money portals',
            'Social media tools',
        ],
        'cadence' => [
            'Per shift',
            'Daily',
            'Weekly',
            'Monthly',
            'Only when asked',
        ],
        'escList' => [
            'Yes, up to date',
            'Yes, out of date',
            'No',
        ],
        'reportChannels' => [
            'WhatsApp',
            'Email',
            'Phone call',
            'Ticketing system',
            'Teams / Slack',
            'Face to face',
            'Shared sheet',
        ],
        'itHear' => [
            'Formal email from IT',
            'WhatsApp message',
            'Release notes',
            'A meeting',
            'From a colleague',
            'From customers',
            'We find out when it breaks',
        ],
        'itHearIT' => [
            'Email to all teams',
            'WhatsApp groups',
            'Release notes',
            'A meeting',
            'Direct message to heads',
            "We often don't announce",
        ],
        'notice' => [
            'None',
            'Same day',
            '1–2 days',
            '3–6 days',
            'A week or more',
        ],
        'itNeeds' => [
            'What is changing',
            'Date and time window',
            'Which markets',
            'Expected downtime',
            'Customer impact',
            'What to tell customers (script)',
            'Promo or bonus impact',
            'How to test it',
            'Rollback plan',
            'Who to contact',
            'Screenshots / demo',
        ],
        'helps' => [
            'One shared incident channel',
            'Change and release calendar',
            'Daily status bulletin',
            'Escalation matrix with names and numbers',
            'Customer-facing scripts for every change',
            'Weekly cross-team sync',
            'Knowledge base / FAQ',
            'Promo launch checklist',
            'Post-incident summaries',
            'Shift handover notes',
            'Single ticketing system',
            'Dashboards shared across teams',
        ],
    ],

    'impact' => ['Low', 'Medium', 'High', 'Critical'],

    'frequency' => ['Daily', 'Weekly', 'Monthly', 'Rarely'],

    'limits' => [
        'problems' => 3,
        'helps' => 5,
    ],
];
