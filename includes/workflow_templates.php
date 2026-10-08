<?php
/**
 * CUBOIDPILOT / CAI — PRE-BUILT VISUAL AI WORKFLOW TEMPLATES
 * Ready-to-use production workflows with typed nodes and directed edges.
 */

class WorkflowTemplates {

    public static function getAll(): array {
        return [
            self::getUniversalCustomerJourneyTemplate(),
            self::getMasterAutonomousJourneyTemplate(),
            self::getCourseEnrollmentTemplate(),
            self::getAiSalesAssistantTemplate(),
            self::getEmiCollectionTemplate(),
            self::getLeadQualificationTemplate(),
            self::getAppointmentBookingTemplate(),
            self::getBrochureDeliveryTemplate(),
            self::getHumanHandoffTemplate()
        ];
    }

    public static function getById(string $id): ?array {
        foreach (self::getAll() as $t) {
            if ($t['id'] === $id) return $t;
        }
        return null;
    }

    /**
     * Flagship 5-Phase Universal AI Customer Journey
     * Grounded in multi-tenant knowledge store, offerings catalog, and digital assets.
     */
    public static function getUniversalCustomerJourneyTemplate(): array {
        return [
            'id' => 'template_universal_customer_journey',
            'name' => 'Universal AI Customer Journey (Cai AI)',
            'description' => 'Complete 5-Phase Knowledge-Grounded Journey: Welcome & Discovery, Intent Understanding, Grounded Knowledge Recommendations, Progressive Action Engine, and Resolution.',
            'category' => 'Flagship Journey',
            'trigger_type' => 'trigger_chat_start',
            'mode' => 'guided',
            'nodes' => [
                [
                    'id' => 'node_1',
                    'type' => 'trigger_chat_start',
                    'category' => 'triggers',
                    'position' => ['x' => 60, 'y' => 60],
                    'data' => [
                        'label' => '1. Visitor Arrives / Chat Start',
                        'description' => 'Triggered when visitor opens widget or starts conversation'
                    ]
                ],
                [
                    'id' => 'node_2',
                    'type' => 'ai_response_generator',
                    'category' => 'ai',
                    'position' => ['x' => 360, 'y' => 60],
                    'data' => [
                        'label' => 'Phase 1: Welcome & Context',
                        'description' => 'Personalized brand greeting with 3 contextual quick chips',
                        'prompt' => 'Welcome the visitor warmly to {{company.name}}. Introduce yourself as Cai, their consultative AI advisor. Ask how you can assist them today, and offer to explore offerings, review pricing/EMI plans, or connect with a specialist.',
                        'wait_for_reply' => true,
                        'action_chips' => [
                            ['label' => 'Explore Offerings', 'text' => 'Tell me about your offerings and programs'],
                            ['label' => 'Pricing & EMI', 'text' => 'What are the pricing and payment options?'],
                            ['label' => 'Talk to Specialist', 'text' => 'I would like to speak with a human counselor']
                        ]
                    ]
                ],
                [
                    'id' => 'node_3',
                    'type' => 'ai_intent_detection',
                    'category' => 'ai',
                    'position' => ['x' => 660, 'y' => 60],
                    'data' => [
                        'label' => 'Phase 2: Intent Classification',
                        'description' => 'Understands intent: discovery, pricing, brochure, booking, payment, or human assistance',
                        'intents' => [
                            'discovery' => 'Looking for programs, courses, or solutions',
                            'pricing' => 'Asking about fees, pricing, discounts, or EMI plans',
                            'brochure' => 'Requesting syllabus, brochure, or documentation',
                            'booking' => 'Wants consultation, demo, or 1-on-1 appointment',
                            'payment' => 'Ready to pay, enroll, or checkout',
                            'human_support' => 'Requests to speak to counselor or real human'
                        ]
                    ]
                ],
                [
                    'id' => 'node_4',
                    'type' => 'ai_response_generator',
                    'category' => 'ai',
                    'position' => ['x' => 960, 'y' => 60],
                    'data' => [
                        'label' => 'Phase 2: Grounded Knowledge Guidance',
                        'description' => 'Accurately answers inquiries strictly grounded in verified company documents and catalog',
                        'prompt' => 'Consult the verified company knowledge and offerings catalog. Provide a concise, helpful, and 100% accurate answer to the user\'s requirement. NEVER guess prices or unlisted features. If information is not on file, politely disclose this and offer human assistance.',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_5',
                    'type' => 'msg_product_carousel',
                    'category' => 'messages',
                    'position' => ['x' => 60, 'y' => 280],
                    'data' => [
                        'label' => 'Phase 3: Solutions & Offerings Carousel',
                        'description' => 'Displays matching offerings with pricing, duration, and features',
                        'title' => 'Here are our verified offerings matching your requirement:',
                        'limit' => 4,
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_6',
                    'type' => 'crm_create_lead',
                    'category' => 'crm',
                    'position' => ['x' => 360, 'y' => 280],
                    'data' => [
                        'label' => 'Phase 4: Progressive CRM Lead Capture',
                        'description' => 'Captures lead in CRM and marks stage as QUALIFIED',
                        'stage' => 'QUALIFIED',
                        'tags' => 'website_visitor,ai_grounded,high_intent'
                    ]
                ],
                [
                    'id' => 'node_7',
                    'type' => 'msg_emi_card',
                    'category' => 'messages',
                    'position' => ['x' => 660, 'y' => 280],
                    'data' => [
                        'label' => 'Phase 4: Flexible Installment & EMI Breakdown',
                        'description' => 'Presents transparent 3-Month installment options',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_8',
                    'type' => 'msg_brochure_download',
                    'category' => 'messages',
                    'position' => ['x' => 960, 'y' => 280],
                    'data' => [
                        'label' => 'Phase 4: Overview & Document Delivery',
                        'description' => 'Delivers verified company documents and brochures',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_9',
                    'type' => 'msg_payment_link',
                    'category' => 'messages',
                    'position' => ['x' => 60, 'y' => 500],
                    'data' => [
                        'label' => 'Phase 4: Verified Payment Checkout',
                        'description' => 'Delivers secure instant payment checkout link',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_10',
                    'type' => 'crm_request_handoff',
                    'category' => 'crm',
                    'position' => ['x' => 360, 'y' => 500],
                    'data' => [
                        'label' => 'Phase 4: Human Specialist Handoff',
                        'description' => 'Connects customer with human team member if requested'
                    ]
                ],
                [
                    'id' => 'node_11',
                    'type' => 'comm_channel_dispatch',
                    'category' => 'reminders',
                    'position' => ['x' => 660, 'y' => 500],
                    'data' => [
                        'label' => 'Phase 5: Multi-Channel Dispatch & Confirmation',
                        'description' => 'Dispatches complete details to Email or WhatsApp',
                        'message' => 'Would you like us to email you the complete details and invoice, or send it directly on WhatsApp?',
                        'options' => [
                            ['label' => 'Send via Email', 'text' => 'Please email me the details'],
                            ['label' => 'Send on WhatsApp', 'text' => 'Please send on WhatsApp'],
                            ['label' => 'Talk to Team', 'text' => 'I want to speak with a representative']
                        ],
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_12',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 960, 'y' => 500],
                    'data' => [
                        'label' => 'Phase 5: Journey Complete & Resolution',
                        'description' => 'Autonomous customer journey successfully completed'
                    ]
                ]
            ],
            'edges' => [
                ['id' => 'e1_2', 'source' => 'node_1', 'target' => 'node_2'],
                ['id' => 'e2_3', 'source' => 'node_2', 'target' => 'node_3'],
                ['id' => 'e3_4', 'source' => 'node_3', 'target' => 'node_4'],
                ['id' => 'e4_5', 'source' => 'node_4', 'target' => 'node_5'],
                ['id' => 'e5_6', 'source' => 'node_5', 'target' => 'node_6'],
                ['id' => 'e6_7', 'source' => 'node_6', 'target' => 'node_7'],
                ['id' => 'e7_8', 'source' => 'node_7', 'target' => 'node_8'],
                ['id' => 'e8_9', 'source' => 'node_8', 'target' => 'node_9'],
                ['id' => 'e9_10', 'source' => 'node_9', 'target' => 'node_10'],
                ['id' => 'e10_11', 'source' => 'node_10', 'target' => 'node_11'],
                ['id' => 'e11_12', 'source' => 'node_11', 'target' => 'node_12']
            ]
        ];
    }

    /**
     * Master Autonomous Customer Journey (Complete Omnichannel Journey)
     * Full flow: User arrives -> AI greets & creates lead -> conversation starts -> understands requirement & answers ->
     * carousel if needed -> information & brochure -> payment flow (EMI) -> payment options -> multichannel (email/whatsapp) -> human counselor handoff -> complete.
     */
    public static function getMasterAutonomousJourneyTemplate(): array {
        return [
            'id' => 'template_master_autonomous_journey',
            'name' => 'Autonomous Customer Journey (Cai AI)',
            'description' => 'Complete intelligent journey: AI Greeting, Auto Lead Capture, Requirement Understanding, Dynamic Carousel, Information & Brochure, Payment & EMI Flow, Multichannel Email/WhatsApp Dispatch, and Human Counselor Handoff.',
            'category' => 'Flagship Journey',
            'trigger_type' => 'trigger_chat_start',
            'mode' => 'guided',
            'nodes' => [
                [
                    'id' => 'node_1',
                    'type' => 'trigger_chat_start',
                    'category' => 'triggers',
                    'position' => ['x' => 80, 'y' => 180],
                    'data' => [
                        'label' => '1. User Arrives / Chat Started',
                        'description' => 'Triggered when visitor lands or starts conversation'
                    ]
                ],
                [
                    'id' => 'node_2',
                    'type' => 'crm_create_lead',
                    'category' => 'crm',
                    'position' => ['x' => 380, 'y' => 180],
                    'data' => [
                        'label' => '2. Auto Create & Tag Lead',
                        'description' => 'Instantly creates lead in CRM with status QUALIFIED',
                        'stage' => 'QUALIFIED',
                        'tags' => 'website_visitor,ai_engaged'
                    ]
                ],
                [
                    'id' => 'node_3',
                    'type' => 'ai_response_generator',
                    'category' => 'ai',
                    'position' => ['x' => 680, 'y' => 180],
                    'data' => [
                        'label' => '3. AI Warm Greeting & Start Conversation',
                        'description' => 'Welcomes visitor and introduces Cai',
                        'prompt' => "Welcome the visitor warmly to CuboidSoft. Introduce yourself as Cai, their autonomous AI assistant. Ask what specific goal or requirement they would like to explore today.",
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_4',
                    'type' => 'ai_qualification',
                    'category' => 'ai',
                    'position' => ['x' => 980, 'y' => 180],
                    'data' => [
                        'label' => '4. Understand Requirement & Answer Questions',
                        'description' => 'Understands user question/intent, answers with grounded knowledge',
                        'question' => "What specific skills, programs, or solutions are you looking to implement today?",
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_5',
                    'type' => 'msg_product_carousel',
                    'category' => 'messages',
                    'position' => ['x' => 1280, 'y' => 180],
                    'data' => [
                        'label' => '5. Solution & Offering Carousel',
                        'description' => 'Displays matching offerings with pricing, duration, features',
                        'title' => "Here are our solutions and offerings matching your goals:",
                        'limit' => 4,
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_6',
                    'type' => 'msg_brochure_download',
                    'category' => 'messages',
                    'position' => ['x' => 1580, 'y' => 180],
                    'data' => [
                        'label' => '6. Information & Overview Document',
                        'description' => 'Delivers verified company documents and brochures',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_7',
                    'type' => 'msg_emi_card',
                    'category' => 'messages',
                    'position' => ['x' => 1880, 'y' => 180],
                    'data' => [
                        'label' => '7. Payment Flow & Installment Breakdown',
                        'description' => 'Presents transparent 3-Month installment options',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_8',
                    'type' => 'msg_payment_link',
                    'category' => 'messages',
                    'position' => ['x' => 2180, 'y' => 180],
                    'data' => [
                        'label' => '8. Payment Options & Checkout Link',
                        'description' => 'Delivers secure instant payment checkout link',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_9',
                    'type' => 'comm_channel_dispatch',
                    'category' => 'reminders',
                    'position' => ['x' => 2480, 'y' => 180],
                    'data' => [
                        'label' => '9. Multichannel Dispatch (Email / WhatsApp)',
                        'description' => 'Option to dispatch complete details to Email or WhatsApp',
                        'message' => "Would you like us to email you the complete details and invoice, or send it directly on WhatsApp?",
                        'options' => [
                            ['label' => 'Send via Email', 'text' => 'Please email me the details'],
                            ['label' => 'Send on WhatsApp', 'text' => 'Please send on WhatsApp'],
                            ['label' => 'Talk to Team', 'text' => 'I want to speak with a representative']
                        ],
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_10',
                    'type' => 'crm_request_handoff',
                    'category' => 'crm',
                    'position' => ['x' => 2780, 'y' => 180],
                    'data' => [
                        'label' => '10. Real Human Counselor Escalation',
                        'description' => 'Connects customer with human counselor if requested'
                    ]
                ],
                [
                    'id' => 'node_11',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 3080, 'y' => 180],
                    'data' => [
                        'label' => '11. Journey Complete',
                        'description' => 'Autonomous customer journey successfully completed'
                    ]
                ]
            ],
            'edges' => [
                ['id' => 'e1_2', 'source' => 'node_1', 'target' => 'node_2'],
                ['id' => 'e2_3', 'source' => 'node_2', 'target' => 'node_3'],
                ['id' => 'e3_4', 'source' => 'node_3', 'target' => 'node_4'],
                ['id' => 'e4_5', 'source' => 'node_4', 'target' => 'node_5'],
                ['id' => 'e5_6', 'source' => 'node_5', 'target' => 'node_6'],
                ['id' => 'e6_7', 'source' => 'node_6', 'target' => 'node_7'],
                ['id' => 'e7_8', 'source' => 'node_7', 'target' => 'node_8'],
                ['id' => 'e8_9', 'source' => 'node_8', 'target' => 'node_9'],
                ['id' => 'e9_10', 'source' => 'node_9', 'target' => 'node_10'],
                ['id' => 'e10_11', 'source' => 'node_10', 'target' => 'node_11']
            ]
        ];
    }

    /**
     * Complete Sample Workflow — Course Enrollment (Section 9)
     */
    public static function getCourseEnrollmentTemplate(): array {
        return [
            'id' => 'template_course_enrollment',
            'name' => 'Course Enrollment Journey',
            'description' => 'Complete 9-stage guided AI journey: Lead capture, intent detection, dynamic course carousel, EMI options, verified payment, and reminders.',
            'category' => 'EdTech & Courses',
            'trigger_type' => 'trigger_chat_start',
            'mode' => 'guided',
            'nodes' => [
                [
                    'id' => 'node_1',
                    'type' => 'trigger_chat_start',
                    'category' => 'triggers',
                    'position' => ['x' => 80, 'y' => 200],
                    'data' => [
                        'label' => 'New Chat Started',
                        'description' => 'Triggered when visitor opens chat widget'
                    ]
                ],
                [
                    'id' => 'node_2',
                    'type' => 'ai_qualification',
                    'category' => 'ai',
                    'position' => ['x' => 340, 'y' => 200],
                    'data' => [
                        'label' => 'Stage 1: Greeting & Lead Capture',
                        'description' => 'Welcome prospect and inquire about learning goals',
                        'question' => "Namaste! Welcome to our learning academy. I am Cai, your AI counselor. Which domain or career skill are you looking to master?",
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_3',
                    'type' => 'ai_intent_detection',
                    'category' => 'ai',
                    'position' => ['x' => 640, 'y' => 200],
                    'data' => [
                        'label' => 'Stage 2: AI Intent Detection',
                        'description' => 'Classify goal into specific cohort interests',
                        'intents' => [
                            'web_dev' => 'Full stack web development, React, Node',
                            'ai_ml' => 'Generative AI, Machine Learning, Python',
                            'counseling' => 'Need career guidance or counseling'
                        ]
                    ]
                ],
                [
                    'id' => 'node_4',
                    'type' => 'msg_course_carousel',
                    'category' => 'messages',
                    'position' => ['x' => 960, 'y' => 120],
                    'data' => [
                        'label' => 'Stage 3: Course Recommendation Carousel',
                        'description' => 'Display matching live courses from catalog',
                        'title' => 'Here are our industry-accredited flagship cohorts matching your goals:',
                        'category' => 'course',
                        'limit' => 4,
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_5',
                    'type' => 'ai_response_generator',
                    'category' => 'ai',
                    'position' => ['x' => 1280, 'y' => 120],
                    'data' => [
                        'label' => 'Stage 4: AI Course Explanation',
                        'description' => 'Explain curriculum, duration, and placement assistance',
                        'prompt' => 'Explain the chosen course curriculum, live project scope, and prerequisites clearly based on company knowledge.',
                        'temperature' => 0.3,
                        'wait_for_reply' => false
                    ]
                ],
                [
                    'id' => 'node_6',
                    'type' => 'logic_emi_availability',
                    'category' => 'logic',
                    'position' => ['x' => 1580, 'y' => 120],
                    'data' => [
                        'label' => 'Stage 5: EMI Eligibility Check',
                        'description' => 'Branch on 0% EMI financing availability'
                    ]
                ],
                [
                    'id' => 'node_7',
                    'type' => 'msg_emi_card',
                    'category' => 'messages',
                    'position' => ['x' => 1900, 'y' => 80],
                    'data' => [
                        'label' => 'Stage 5B: 3-Month 0% EMI Options Card',
                        'description' => 'Render interactive zero-cost installment schedule',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_8',
                    'type' => 'msg_payment_link',
                    'category' => 'messages',
                    'position' => ['x' => 2200, 'y' => 120],
                    'data' => [
                        'label' => 'Stage 6: Verified Payment Card',
                        'description' => 'Generate secure payment checkout card',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'node_9',
                    'type' => 'crm_update_lead',
                    'category' => 'crm',
                    'position' => ['x' => 2520, 'y' => 120],
                    'data' => [
                        'label' => 'Stage 7: Mark Lead Converted',
                        'description' => 'Advance pipeline to Won & create student enrollment',
                        'stage' => 'WON'
                    ]
                ],
                [
                    'id' => 'node_10',
                    'type' => 'comm_schedule_reminder',
                    'category' => 'reminders',
                    'position' => ['x' => 2820, 'y' => 120],
                    'data' => [
                        'label' => 'Stage 8: Schedule Onboarding Reminder',
                        'description' => 'Dispatch onboarding welcome & installment calendar',
                        'delay_minutes' => 60,
                        'message' => 'Hi {{customer.name}}, welcome to the cohort! Your student portal credentials and batch schedule are ready.'
                    ]
                ],
                [
                    'id' => 'node_11',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 3120, 'y' => 120],
                    'data' => [
                        'label' => 'Stage 9: Workflow Complete',
                        'description' => 'End customer journey smoothly'
                    ]
                ]
            ],
            'edges' => [
                ['id' => 'e1_2', 'source' => 'node_1', 'target' => 'node_2'],
                ['id' => 'e2_3', 'source' => 'node_2', 'target' => 'node_3'],
                ['id' => 'e3_4', 'source' => 'node_3', 'target' => 'node_4'],
                ['id' => 'e4_5', 'source' => 'node_4', 'target' => 'node_5'],
                ['id' => 'e5_6', 'source' => 'node_5', 'target' => 'node_6'],
                ['id' => 'e6_7', 'source' => 'node_6', 'target' => 'node_7', 'sourceHandle' => 'emi_available', 'label' => 'EMI Available'],
                ['id' => 'e6_8', 'source' => 'node_6', 'target' => 'node_8', 'sourceHandle' => 'full_payment_only', 'label' => 'Full Payment'],
                ['id' => 'e7_8', 'source' => 'node_7', 'target' => 'node_8'],
                ['id' => 'e8_9', 'source' => 'node_8', 'target' => 'node_9'],
                ['id' => 'e9_10', 'source' => 'node_9', 'target' => 'node_10'],
                ['id' => 'e10_11', 'source' => 'node_10', 'target' => 'node_11']
            ]
        ];
    }

    public static function getAiSalesAssistantTemplate(): array {
        return [
            'id' => 'template_sales_assistant',
            'name' => 'AI Consultative Sales Assistant',
            'description' => 'Proactive sales conversion: greets visitors, qualifies budget, recommends solutions, and issues checkout links.',
            'category' => 'Sales & Revenue',
            'trigger_type' => 'trigger_chat_start',
            'mode' => 'guided',
            'nodes' => [
                [
                    'id' => 's_node_1',
                    'type' => 'trigger_chat_start',
                    'category' => 'triggers',
                    'position' => ['x' => 100, 'y' => 200],
                    'data' => ['label' => 'Chat Started', 'description' => 'Visitor opens widget']
                ],
                [
                    'id' => 's_node_2',
                    'type' => 'ai_response_generator',
                    'category' => 'ai',
                    'position' => ['x' => 380, 'y' => 200],
                    'data' => [
                        'label' => 'Consultative Greeting',
                        'prompt' => 'Greet the prospect warmly and ask what commercial problem their team is looking to solve today.',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 's_node_3',
                    'type' => 'ai_product_recommendation',
                    'category' => 'ai',
                    'position' => ['x' => 680, 'y' => 200],
                    'data' => ['label' => 'Match Solutions', 'description' => 'Recommend platform plans based on needs']
                ],
                [
                    'id' => 's_node_4',
                    'type' => 'msg_payment_link',
                    'category' => 'messages',
                    'position' => ['x' => 980, 'y' => 200],
                    'data' => ['label' => 'Instant Checkout Card', 'description' => 'Deliver secure payment link']
                ],
                [
                    'id' => 's_node_5',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 1280, 'y' => 200],
                    'data' => ['label' => 'Finish Journey']
                ]
            ],
            'edges' => [
                ['id' => 'se_1_2', 'source' => 's_node_1', 'target' => 's_node_2'],
                ['id' => 'se_2_3', 'source' => 's_node_2', 'target' => 's_node_3'],
                ['id' => 'se_3_4', 'source' => 's_node_3', 'target' => 's_node_4'],
                ['id' => 'se_4_5', 'source' => 's_node_4', 'target' => 's_node_5']
            ]
        ];
    }

    public static function getEmiCollectionTemplate(): array {
        return [
            'id' => 'template_emi_collection',
            'name' => 'Automated EMI & Fee Reminders',
            'description' => 'Runs against existing students and customers to verify upcoming fee deadlines and send automated WhatsApp reminders.',
            'category' => 'Installments & Finance',
            'trigger_type' => 'trigger_scheduled',
            'mode' => 'strict',
            'nodes' => [
                [
                    'id' => 'e_node_1',
                    'type' => 'trigger_scheduled',
                    'category' => 'triggers',
                    'position' => ['x' => 100, 'y' => 200],
                    'data' => ['label' => 'Installment Lookahead (4 Days)', 'description' => 'Checks due installments']
                ],
                [
                    'id' => 'e_node_2',
                    'type' => 'msg_emi_card',
                    'category' => 'messages',
                    'position' => ['x' => 400, 'y' => 200],
                    'data' => ['label' => 'Installment Status Card', 'description' => 'Display remaining balance and due date']
                ],
                [
                    'id' => 'e_node_3',
                    'type' => 'comm_schedule_reminder',
                    'category' => 'reminders',
                    'position' => ['x' => 700, 'y' => 200],
                    'data' => [
                        'label' => 'WhatsApp Reminder Dispatch',
                        'delay_minutes' => 0,
                        'message' => 'Hi {{customer.name}}, friendly reminder that installment #2 for your cohort is due on {{payment.nextDueDate}}.'
                    ]
                ],
                [
                    'id' => 'e_node_4',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 1000, 'y' => 200],
                    'data' => ['label' => 'Complete Cycle']
                ]
            ],
            'edges' => [
                ['id' => 'ee_1_2', 'source' => 'e_node_1', 'target' => 'e_node_2'],
                ['id' => 'ee_2_3', 'source' => 'e_node_2', 'target' => 'e_node_3'],
                ['id' => 'ee_3_4', 'source' => 'e_node_3', 'target' => 'e_node_4']
            ]
        ];
    }

    public static function getLeadQualificationTemplate(): array {
        return [
            'id' => 'template_lead_qualification',
            'name' => 'High-Urgency Lead Qualification',
            'description' => 'Extracts requirements, evaluates lead budget, scores opportunity, and alerts sales team immediately.',
            'category' => 'CRM & Pipeline',
            'trigger_type' => 'trigger_new_visitor',
            'mode' => 'guided',
            'nodes' => [
                [
                    'id' => 'l_node_1',
                    'type' => 'trigger_new_visitor',
                    'category' => 'triggers',
                    'position' => ['x' => 100, 'y' => 200],
                    'data' => ['label' => 'New Visitor Arrives']
                ],
                [
                    'id' => 'l_node_2',
                    'type' => 'ai_qualification',
                    'category' => 'ai',
                    'position' => ['x' => 380, 'y' => 200],
                    'data' => [
                        'label' => 'Qualify Requirement',
                        'question' => "Hi there! What is the primary project or requirement you'd like to get started with?",
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'l_node_3',
                    'type' => 'crm_update_lead',
                    'category' => 'crm',
                    'position' => ['x' => 680, 'y' => 200],
                    'data' => ['label' => 'Score & Advance Lead', 'stage' => 'QUALIFIED']
                ],
                [
                    'id' => 'l_node_4',
                    'type' => 'crm_request_handoff',
                    'category' => 'crm',
                    'position' => ['x' => 980, 'y' => 200],
                    'data' => ['label' => 'Notify On-Call Sales Rep']
                ],
                [
                    'id' => 'l_node_5',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 1280, 'y' => 200],
                    'data' => ['label' => 'Complete']
                ]
            ],
            'edges' => [
                ['id' => 'le_1_2', 'source' => 'l_node_1', 'target' => 'l_node_2'],
                ['id' => 'le_2_3', 'source' => 'l_node_2', 'target' => 'l_node_3'],
                ['id' => 'le_3_4', 'source' => 'l_node_3', 'target' => 'l_node_4'],
                ['id' => 'le_4_5', 'source' => 'l_node_4', 'target' => 'l_node_5']
            ]
        ];
    }

    public static function getAppointmentBookingTemplate(): array {
        return [
            'id' => 'template_appointment_booking',
            'name' => 'Consultation & Demo Booking',
            'description' => 'Automates consultation scheduling with real Google Meet calendar slot booking.',
            'category' => 'Appointments',
            'trigger_type' => 'trigger_customer_message',
            'mode' => 'guided',
            'nodes' => [
                [
                    'id' => 'a_node_1',
                    'type' => 'trigger_customer_message',
                    'category' => 'triggers',
                    'position' => ['x' => 100, 'y' => 200],
                    'data' => ['label' => 'Wants 1-on-1 Demo']
                ],
                [
                    'id' => 'a_node_2',
                    'type' => 'msg_appointment_card',
                    'category' => 'messages',
                    'position' => ['x' => 400, 'y' => 200],
                    'data' => [
                        'label' => 'Interactive Slot Picker',
                        'description' => 'Present real-time calendar availability slots',
                        'wait_for_reply' => true
                    ]
                ],
                [
                    'id' => 'a_node_3',
                    'type' => 'crm_update_lead',
                    'category' => 'crm',
                    'position' => ['x' => 700, 'y' => 200],
                    'data' => ['label' => 'Advance to Demo Booked', 'stage' => 'DEMO_SCHEDULED']
                ],
                [
                    'id' => 'a_node_4',
                    'type' => 'comm_schedule_reminder',
                    'category' => 'reminders',
                    'position' => ['x' => 1000, 'y' => 200],
                    'data' => [
                        'label' => 'Pre-Meeting WhatsApp Reminder',
                        'delay_minutes' => 60,
                        'message' => 'Your demo session starts in 1 hour! Here is your Google Meet link.'
                    ]
                ],
                [
                    'id' => 'a_node_5',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 1300, 'y' => 200],
                    'data' => ['label' => 'Complete']
                ]
            ],
            'edges' => [
                ['id' => 'ae_1_2', 'source' => 'a_node_1', 'target' => 'a_node_2'],
                ['id' => 'ae_2_3', 'source' => 'a_node_2', 'target' => 'a_node_3'],
                ['id' => 'ae_3_4', 'source' => 'a_node_3', 'target' => 'a_node_4'],
                ['id' => 'ae_4_5', 'source' => 'a_node_4', 'target' => 'a_node_5']
            ]
        ];
    }

    public static function getBrochureDeliveryTemplate(): array {
        return [
            'id' => 'template_brochure_delivery',
            'name' => 'Brochure & Curriculum Dispatch',
            'description' => 'Delivers verified company documents and brochures directly in chat or to customer email.',
            'category' => 'Documents & Assets',
            'trigger_type' => 'trigger_customer_message',
            'mode' => 'guided',
            'nodes' => [
                [
                    'id' => 'b_node_1',
                    'type' => 'trigger_customer_message',
                    'category' => 'triggers',
                    'position' => ['x' => 100, 'y' => 200],
                    'data' => ['label' => 'Customer Inquires for Brochure']
                ],
                [
                    'id' => 'b_node_2',
                    'type' => 'msg_brochure_download',
                    'category' => 'messages',
                    'position' => ['x' => 400, 'y' => 200],
                    'data' => ['label' => 'Present Brochure Download Card', 'wait_for_reply' => true]
                ],
                [
                    'id' => 'b_node_3',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 700, 'y' => 200],
                    'data' => ['label' => 'Complete']
                ]
            ],
            'edges' => [
                ['id' => 'be_1_2', 'source' => 'b_node_1', 'target' => 'b_node_2'],
                ['id' => 'be_2_3', 'source' => 'b_node_2', 'target' => 'b_node_3']
            ]
        ];
    }

    public static function getHumanHandoffTemplate(): array {
        return [
            'id' => 'template_human_handoff',
            'name' => '1-Tap Human Counselor Escalation',
            'description' => 'Handles sensitive objections, refund requests, or customer trust questions with full AI brief to team.',
            'category' => 'Support & Handoff',
            'trigger_type' => 'trigger_intent_detected',
            'mode' => 'strict',
            'nodes' => [
                [
                    'id' => 'h_node_1',
                    'type' => 'trigger_intent_detected',
                    'category' => 'triggers',
                    'position' => ['x' => 100, 'y' => 200],
                    'data' => ['label' => 'Trust Objection / Human Requested']
                ],
                [
                    'id' => 'h_node_2',
                    'type' => 'crm_request_handoff',
                    'category' => 'crm',
                    'position' => ['x' => 400, 'y' => 200],
                    'data' => ['label' => 'Transfer Ownership to Counselor']
                ],
                [
                    'id' => 'h_node_3',
                    'type' => 'comm_send_whatsapp',
                    'category' => 'reminders',
                    'position' => ['x' => 700, 'y' => 200],
                    'data' => [
                        'label' => 'Alert On-Call Team Member',
                        'message' => 'Urgent Lead Handoff: Customer {{customer.name}} requested human assistance.'
                    ]
                ],
                [
                    'id' => 'h_node_4',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 1000, 'y' => 200],
                    'data' => ['label' => 'Escalated']
                ]
            ],
            'edges' => [
                ['id' => 'he_1_2', 'source' => 'h_node_1', 'target' => 'h_node_2'],
                ['id' => 'he_2_3', 'source' => 'h_node_2', 'target' => 'h_node_3'],
                ['id' => 'he_3_4', 'source' => 'h_node_3', 'target' => 'h_node_4']
            ]
        ];
    }

    /**
     * Converts a legacy 1-action automation recipe into an equivalent visual graph.
     */
    public static function convertLegacyRecipeToGraph(array $rule): array {
        $triggerKey = $rule['trigger_event'] ?? 'chat_start';
        $actionKey = $rule['action_type'] ?? 'send_whatsapp_reminder';

        return [
            'mode' => 'guided',
            'nodes' => [
                [
                    'id' => 'n_trig',
                    'type' => 'trigger_' . $triggerKey,
                    'category' => 'triggers',
                    'position' => ['x' => 120, 'y' => 200],
                    'data' => [
                        'label' => 'Trigger: ' . ucwords(str_replace('_', ' ', $triggerKey)),
                        'event' => $triggerKey
                    ]
                ],
                [
                    'id' => 'n_act',
                    'type' => $actionKey,
                    'category' => 'actions',
                    'position' => ['x' => 450, 'y' => 200],
                    'data' => [
                        'label' => 'Action: ' . ucwords(str_replace('_', ' ', $actionKey)),
                        'wait_minutes' => (int)($rule['wait_minutes'] ?? 0),
                        'condition_key' => $rule['condition_key'] ?? '',
                        'condition_value' => $rule['condition_value'] ?? '',
                        'secondary_action' => $rule['secondary_action'] ?? ''
                    ]
                ],
                [
                    'id' => 'n_end',
                    'type' => 'control_end',
                    'category' => 'control',
                    'position' => ['x' => 780, 'y' => 200],
                    'data' => [
                        'label' => 'End Workflow'
                    ]
                ]
            ],
            'edges' => [
                ['id' => 'e_trig_act', 'source' => 'n_trig', 'target' => 'n_act'],
                ['id' => 'e_act_end', 'source' => 'n_act', 'target' => 'n_end']
            ]
        ];
    }
}
