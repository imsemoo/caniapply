<?php

/*
| Canonical skill name => case-insensitive regex body.
| "tool" skills count half when missing: they are learnable on the job and rarely
| decide a hire on their own.
*/
return [
    'tool_skills' => ['Docker', 'Kubernetes', 'AWS', 'GCP', 'Azure', 'Terraform', 'CI/CD', 'Git', 'Redis', 'Elasticsearch', 'Jira'],

    'dictionary' => [
        'Laravel' => '\blaravel\b|لارافيل|لارافل',
        'PHP' => '\bphp\b|بي اتش بي',
        'Livewire' => '\blivewire\b',
        'Blade' => '\bblade\b',
        'Inertia' => '\binertia(\.?js)?\b',
        'Symfony' => '\bsymfony\b',
        'WordPress' => '\bwordpress\b|\bwp\b|ووردبريس|وردبريس',
        'Drupal' => '\bdrupal\b',
        'Magento' => '\bmagento\b',
        'Shopify' => '\bshopify\b|شوبيفاي',
        'JavaScript' => '\bjavascript\b|\bes6\b|\bjs\b|جافا ?سكريبت',
        'TypeScript' => '\btypescript\b',
        'Alpine.js' => '\balpine(\.?js)?\b',
        'Vue.js' => '\bvue(\.?js)?\b|\bvue ?3\b',
        'Nuxt' => '\bnuxt(\.?js)?\b',
        'React' => '\breact(\.?js)?\b(?! native)',
        'React Native' => '\breact native\b',
        'Next.js' => '\bnext\.?js\b',
        'Angular' => '\bangular(\.?js)?\b',
        'Svelte' => '\bsvelte(kit)?\b',
        'HTML' => '\bhtml5?\b',
        'CSS' => '\bcss3?\b',
        'Sass' => '\bsass\b|\bscss\b',
        'Bootstrap' => '\bbootstrap\b',
        'Tailwind CSS' => '\btailwind(css)?\b',
        'Figma' => '\bfigma\b|فيجما',
        'MySQL' => '\bmysql\b|\bmariadb\b',
        'PostgreSQL' => '\bpostgres(ql)?\b',
        'MongoDB' => '\bmongo(db)?\b',
        'Redis' => '\bredis\b',
        'Elasticsearch' => '\belastic ?search\b',
        'REST APIs' => '\brest(ful)?\b( ?apis?| services)?|\bapi integrations?\b|\bapis\b|واجهات برمجة التطبيقات',
        'GraphQL' => '\bgraphql\b',
        'Node.js' => '\bnode(\.?js)?\b',
        'NestJS' => '\bnest\.?js\b',
        'Python' => '\bpython\b',
        'Django' => '\bdjango\b',
        'Ruby on Rails' => '\bruby\b|\brails\b',
        'Java' => '\bjava\b(?!script)',
        'Spring' => '\bspring ?boot\b',
        '.NET' => '\.net\b|\bc#',
        'Go' => '\bgolang\b',
        'Rust' => '\brust\b',
        'Flutter' => '\bflutter\b|فلاتر',
        'Swift' => '\bswift\b',
        'Kotlin' => '\bkotlin\b',
        'Docker' => '\bdocker\b',
        'Kubernetes' => '\bkubernetes\b|\bk8s\b',
        'AWS' => '\baws\b|amazon web services',
        'GCP' => '\bgcp\b|google cloud',
        'Azure' => '\bazure\b',
        'Terraform' => '\bterraform\b',
        'CI/CD' => '\bci ?/ ?cd\b',
        'Git' => '\bgit\b|\bgithub\b|\bgitlab\b',
        'Jira' => '\bjira\b',
        'SIEM' => '\bsiem\b',
        'Splunk' => '\bsplunk\b',
        'Microsoft Sentinel' => '\bsentinel\b',
        'QRadar' => '\bqradar\b',
        'EDR' => '\bedr\b|\bxdr\b',
        'Linux' => '\blinux\b',
        'Network Security' => 'network security|\bfirewalls?\b|\bids\b|\bips\b',
        'Incident Response' => 'incident (response|handling)|\bdfir\b',
        'MITRE ATT&CK' => 'mitre|att&(amp;)?ck',
        'Wireshark' => '\bwireshark\b',
    ],

    /*
    | A title naming one of these stacks, with none of the candidate's anchor
    | skills beside it, is a different job however much of the rest overlaps.
    */
    'foreign_stacks' => ['React Native', 'Java', 'Spring', '.NET', 'Go', 'Rust', 'Ruby on Rails', 'Python', 'Django', 'Flutter', 'Swift', 'Kotlin', 'Angular', 'NestJS', 'Node.js', 'Shopify', 'Magento'],
];
