<?php

namespace App\Enums\Tutorial;

use App\Enums\Market\MarketCapability;
use App\Models\User;
use App\Services\Market\MarketCapabilities;

/**
 * Where in the dashboard a tutorial video belongs.
 *
 * The vocabulary is an enum and not something an admin types, for the same
 * reason plan features are: the dashboard asks for videos by these keys, so a
 * topic spelled differently in the Back Office is a video nobody ever sees.
 * Adding a case here is the whole job — the Back Office lists it from
 * `/admin/tutorials/meta`, and the dashboard asks for it by key.
 *
 * Topics nest with a dot: `connections.telegram` is a child of `connections`.
 * The parent's page shows its children's videos too (the Connections page is
 * where somebody looks for "how do I connect Telegram"), and a child with no
 * video of its own falls back to its parent's.
 *
 * Each topic names who it is for — permissions (any of) and a market
 * capability — so an agent is not offered a video about a screen their role
 * cannot open, and a country is not offered one about something not sold
 * there. Plan features are deliberately NOT a gate: the sidebar shows pages
 * outside the plan as locked, and a video about one is the best answer to
 * "what would I get".
 */
enum TutorialTopic: string
{
    // Getting started
    case Onboarding = 'onboarding';
    case Dashboard = 'dashboard';

    // Inbox
    case Conversations = 'conversations';
    case Email = 'email';
    case Comments = 'comments';
    case Contacts = 'contacts';
    case QuickMessages = 'quick_messages';
    case Tags = 'tags';

    // Connections — one per channel, keyed by the channel's own value so the
    // dashboard can build the key from the connection it is looking at.
    case Connections = 'connections';
    case ConnectionWhatsappOfficial = 'connections.whatsapp_official';
    case ConnectionApiway = 'connections.whatsapp_proxyhub';
    case ConnectionInstagram = 'connections.instagram';
    case ConnectionMessenger = 'connections.messenger';
    case ConnectionTelegram = 'connections.telegram';
    case ConnectionDiscord = 'connections.discord';
    case ConnectionTiktok = 'connections.tiktok';
    case ConnectionEmail = 'connections.email';
    case ConnectionWidget = 'connections.live_chat_widget';

    // Resources
    case Numbers = 'numbers';
    case Gallery = 'gallery';

    // CRM
    case Crm = 'crm';
    case CrmLeads = 'crm.leads';
    case CrmDeals = 'crm.deals';
    case CrmCompanies = 'crm.companies';
    case CrmSegments = 'crm.segments';
    case CrmTasks = 'crm.tasks';
    case CrmActivities = 'crm.activities';
    case CrmReports = 'crm.reports';
    case CrmPipelines = 'crm.pipelines';
    case CrmAutomations = 'crm.automations';
    case CrmFields = 'crm.fields';
    case CrmLeadScoring = 'crm.lead_scoring';

    // Sales
    case Orders = 'orders';
    case Products = 'products';
    case Reservations = 'reservations';

    // Marketing
    case Campaigns = 'campaigns';
    case Templates = 'templates';
    case PostsInstagram = 'posts.instagram';
    case PostsTiktok = 'posts.tiktok';
    case PostsYoutube = 'posts.youtube';

    // Automation — the flow builder, and one topic per node worth explaining.
    case Flows = 'flows';
    case FlowAssistant = 'flows.assistant';
    case FlowMessage = 'flows.message';
    case FlowInterval = 'flows.interval';
    case FlowWaitResponse = 'flows.wait_response';
    case FlowInteractive = 'flows.interactive';
    case FlowCondition = 'flows.condition';
    case FlowTagging = 'flows.tagging';
    case FlowAction = 'flows.action';
    case FlowStatus = 'flows.status';
    case FlowHttpRequest = 'flows.http_request';
    case FlowAiAgent = 'flows.ai_agent';
    case FlowAiTools = 'flows.ai_tools';
    case FlowAiMedia = 'flows.ai_media';
    case FlowPayment = 'flows.payment';
    case FlowPixKey = 'flows.pix_key';
    case FlowReceipt = 'flows.receipt';
    case FlowInvoice = 'flows.invoice';
    case FlowPixel = 'flows.pixel';
    case FlowGoToFlow = 'flows.go_to_flow';
    case FlowSplitTest = 'flows.split_test';
    case FlowPhoneRouter = 'flows.phone_router';

    // AI agents
    case AiAgents = 'ai_agents';
    case AiAgentCredentials = 'ai_agents.credentials';
    case AiAgentTrained = 'ai_agents.trained';
    case AiAgentVocabulary = 'ai_agents.vocabulary';

    // Insights
    case Live = 'live';
    case Statistics = 'statistics';
    case AgentControl = 'agent_control';
    case Incidents = 'incidents';

    // Account and settings
    case Billing = 'billing';
    case BillingCredits = 'billing.credits';
    case Agents = 'agents';
    case Integrations = 'integrations';
    case Developer = 'developer';
    case Notifications = 'notifications';
    case Affiliate = 'affiliate';

    /** The areas, in the order the dashboard lists them. */
    public const AREAS = [
        'getting_started' => 'Getting started',
        'inbox' => 'Inbox',
        'connections' => 'Connections',
        'resources' => 'Resources',
        'crm' => 'CRM',
        'sales' => 'Sales',
        'marketing' => 'Marketing',
        'automation' => 'Automation',
        'ai' => 'AI agents',
        'insights' => 'Insights',
        'account' => 'Account and settings',
    ];

    public function area(): string
    {
        return match (true) {
            in_array($this, [self::Onboarding, self::Dashboard], true) => 'getting_started',
            in_array($this, [self::Conversations, self::Email, self::Comments, self::Contacts, self::QuickMessages, self::Tags], true) => 'inbox',
            $this === self::Connections || str_starts_with($this->value, 'connections.') => 'connections',
            in_array($this, [self::Numbers, self::Gallery], true) => 'resources',
            $this === self::Crm || str_starts_with($this->value, 'crm.') => 'crm',
            in_array($this, [self::Orders, self::Products, self::Reservations], true) => 'sales',
            in_array($this, [self::Campaigns, self::Templates], true) || str_starts_with($this->value, 'posts.') => 'marketing',
            $this === self::Flows || str_starts_with($this->value, 'flows.') => 'automation',
            $this === self::AiAgents || str_starts_with($this->value, 'ai_agents.') => 'ai',
            in_array($this, [self::Live, self::Statistics, self::AgentControl, self::Incidents], true) => 'insights',
            default => 'account',
        };
    }

    /** The topic this one is a child of, or null for a top-level topic. */
    public function parent(): ?self
    {
        $dot = strrpos($this->value, '.');

        return $dot === false ? null : self::tryFrom(substr($this->value, 0, $dot));
    }

    /** English label; the dashboard translates it, the Back Office shows it as is. */
    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Getting started',
            self::Dashboard => 'Dashboard',
            self::Conversations => 'Conversations',
            self::Email => 'Email',
            self::Comments => 'Comments',
            self::Contacts => 'Contacts',
            self::QuickMessages => 'Quick Messages',
            self::Tags => 'Tags',
            self::Connections => 'Connections',
            self::ConnectionWhatsappOfficial => 'WhatsApp Official',
            self::ConnectionApiway => 'API Way (WhatsApp)',
            self::ConnectionInstagram => 'Instagram',
            self::ConnectionMessenger => 'Messenger',
            self::ConnectionTelegram => 'Telegram',
            self::ConnectionDiscord => 'Discord',
            self::ConnectionTiktok => 'TikTok',
            self::ConnectionEmail => 'Email inbox',
            self::ConnectionWidget => 'Live Chat Widget',
            self::Numbers => 'Virtual numbers',
            self::Gallery => 'Gallery',
            self::Crm => 'CRM overview',
            self::CrmLeads => 'Leads',
            self::CrmDeals => 'Deals',
            self::CrmCompanies => 'Companies',
            self::CrmSegments => 'Segments',
            self::CrmTasks => 'Tasks',
            self::CrmActivities => 'Activities',
            self::CrmReports => 'CRM reports',
            self::CrmPipelines => 'Pipelines',
            self::CrmAutomations => 'CRM automations',
            self::CrmFields => 'CRM fields',
            self::CrmLeadScoring => 'Lead scoring',
            self::Orders => 'Orders',
            self::Products => 'Products',
            self::Reservations => 'Reservations',
            self::Campaigns => 'Campaigns',
            self::Templates => 'WhatsApp templates',
            self::PostsInstagram => 'Instagram posts',
            self::PostsTiktok => 'TikTok posts',
            self::PostsYoutube => 'YouTube posts',
            self::Flows => 'Flows',
            self::FlowAssistant => 'Flow assistant',
            self::FlowMessage => 'Message node',
            self::FlowInterval => 'Interval node',
            self::FlowWaitResponse => 'Wait for reply node',
            self::FlowInteractive => 'Interactive node',
            self::FlowCondition => 'Condition node',
            self::FlowTagging => 'Tagging node',
            self::FlowAction => 'Action node',
            self::FlowStatus => 'Close conversation node',
            self::FlowHttpRequest => 'HTTP request node',
            self::FlowAiAgent => 'AI agent node',
            self::FlowAiTools => 'AI agent with actions node',
            self::FlowAiMedia => 'AI media node',
            self::FlowPayment => 'Payment node',
            self::FlowPixKey => 'Pix key node',
            self::FlowReceipt => 'Receipt node',
            self::FlowInvoice => 'Invoice node',
            self::FlowPixel => 'Pixel node',
            self::FlowGoToFlow => 'Go to flow node',
            self::FlowSplitTest => 'A/B test node',
            self::FlowPhoneRouter => 'Route by country/area code node',
            self::AiAgents => 'AI agents',
            self::AiAgentCredentials => 'AI keys and token rental',
            self::AiAgentTrained => 'Ready-made agents',
            self::AiAgentVocabulary => 'Audio vocabulary',
            self::Live => 'Live',
            self::Statistics => 'Statistics',
            self::AgentControl => 'Agent control',
            self::Incidents => 'Incidents',
            self::Billing => 'Plan and billing',
            self::BillingCredits => 'Balance and top-ups',
            self::Agents => 'Agents and roles',
            self::Integrations => 'Integrations',
            self::Developer => 'Developer',
            self::Notifications => 'Notifications',
            self::Affiliate => 'Affiliate program',
        };
    }

    /**
     * Who the topic is for: any one of these permissions. Empty = everybody in
     * a workspace. Mirrors what the sidebar asks before showing the page.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Numbers => ['numbers.view'],
            self::Gallery => ['gallery.view'],
            self::Crm => ['leads.view', 'deals.view', 'crm-tasks.manage'],
            self::CrmLeads => ['leads.view'],
            self::CrmDeals => ['deals.view'],
            self::CrmCompanies => ['companies.view'],
            self::CrmSegments => ['customers.view'],
            self::CrmTasks => ['crm-tasks.manage'],
            self::CrmActivities => ['crm-activities.manage'],
            self::CrmReports => ['crm-reports.view'],
            self::CrmPipelines => ['pipelines.manage'],
            self::CrmAutomations => ['crm-automations.manage'],
            self::CrmFields, self::CrmLeadScoring => ['crm-settings.manage'],
            self::Orders => ['orders.view'],
            self::Products => ['products.view'],
            self::Reservations => ['reservations.view', 'reservations.manage'],
            self::Campaigns => ['broadcasts.view'],
            self::Templates => ['templates.view'],
            self::PostsInstagram => ['instagram-posts.view'],
            self::PostsTiktok => ['tiktok-posts.view'],
            self::PostsYoutube => ['youtube-posts.view', 'youtube-accounts.manage'],
            self::FlowAssistant => ['flows.update'],
            self::AiAgents, self::AiAgentCredentials, self::AiAgentTrained, self::AiAgentVocabulary => ['ai-agents.view'],
            self::Live => ['statistics.tenant.view'],
            self::Statistics => ['statistics.tenant.view', 'statistics.agents.view'],
            self::AgentControl => ['agents.set-offline'],
            self::Incidents => ['incidents.view'],
            self::Billing, self::BillingCredits => ['billing.view'],
            self::Agents => ['agents.view', 'roles.view'],
            self::Integrations => ['integrations.view'],
            default => $this === self::Flows || str_starts_with($this->value, 'flows.') ? ['flows.view'] : [],
        };
    }

    /** A market capability the topic needs, when it is about something not sold everywhere. */
    public function capability(): ?string
    {
        return match ($this) {
            self::Numbers => MarketCapability::VirtualNumbers->value,
            default => null,
        };
    }

    /** Whether this person should be offered videos on this topic. */
    public function availableTo(User $user, array $permissions): bool
    {
        $needed = $this->permissions();

        if ($needed !== [] && array_intersect($needed, $permissions) === []) {
            return false;
        }

        $capability = $this->capability();

        return $capability === null || MarketCapabilities::allowsFor($user->tenant, $capability);
    }

    /**
     * The topics this person may see, in the enum's order.
     *
     * @return list<self>
     */
    public static function availableFor(User $user): array
    {
        $permissions = $user->getAllPermissions()->pluck('name')->all();

        return array_values(array_filter(self::cases(), fn (self $topic) => $topic->availableTo($user, $permissions)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $topic) => $topic->value, self::cases());
    }

    /** @return array{key: string, label: string, area: string, parent: ?string} */
    public function describe(): array
    {
        return [
            'key' => $this->value,
            'label' => $this->label(),
            'area' => $this->area(),
            'parent' => $this->parent()?->value,
        ];
    }
}
