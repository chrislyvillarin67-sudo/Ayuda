<?php
// Figure out the right relative path prefix depending on whether the
// including page lives at the project root or inside /beneficiary/.
$__inBeneficiaryDir = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/beneficiary/') !== false;
$__assetPrefix = $__inBeneficiaryDir ? '../' : '';

// Look for a custom-uploaded chatbot icon (set from Profile / Settings by
// the District Admin). Falls back to the default built-in icon if none.
// Resolve the chat photo. Preference order:
//   1) assets/uploads/assistant.<ext>   (uploaded icon; neutral name works on hosts)
//   2) assets/uploads/profiles/gem.jpg   (the built-in default photo)
//   3) assets/uploads/chatbot_icon.<ext> (legacy name; some hosts block this URL)
// If none exist the widget falls back to the built-in SVG bubble.
$__chatbotIconFile = null;
foreach (['assistant', 'chatbot_icon'] as $__base) {
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $__ext) {
        $__candidate = __DIR__ . '/../assets/uploads/' . $__base . '.' . $__ext;
        if (is_file($__candidate)) {
            $mtime = @filemtime($__candidate);
            $__chatbotIconFile = $__assetPrefix . 'assets/uploads/' . $__base . '.' . $__ext . ($mtime ? '?v=' . $mtime : '');
            break 2;
        }
    }
}
if (!$__chatbotIconFile && is_file(__DIR__ . '/../assets/uploads/profiles/gem.jpg')) {
    $__mtime = @filemtime(__DIR__ . '/../assets/uploads/profiles/gem.jpg');
    $__chatbotIconFile = $__assetPrefix . 'assets/uploads/profiles/gem.jpg' . ($__mtime ? '?v=' . $__mtime : '');
}

// ---- Determine who is looking at the page (so greetings / quick questions
//      and answers can be tailored). ----
$__chatRole = 'public';
if (isset($_SESSION['beneficiary_id'])) $__chatRole = 'beneficiary';
elseif (isset($_SESSION['role'])) $__chatRole = ($_SESSION['role'] === 'district_admin') ? 'admin' : 'staff';

$__chatOffTopic = "I can only help with questions about the Ayuda Monitoring System app itself — the public updates page, announcements, beneficiaries, import/export, final list, distribution, inventory, monitoring, reports, map, calendar, messages, programs, and accounts. For anything else, please check with your Barangay Hall or District Admin.";

$__chatFallback = "I can honestly say I'm not sure about that one yet. I can still help with: viewing updates and the final list, claiming ayuda, announcements, emergency reports, the final list notice, inventory, distribution, reports, the map, calendar, messages, programs, and accounts. Ask it in your own words, tap a quick question, or visit your Barangay Hall / District Admin for specific cases.";

// ---- Live totals (guarded): lets the bot answer real "how many / how much"
//      questions with up-to-date numbers instead of a generic reply. ----
$__live = null;
if (isset($pdo)) {
    try {
        $__q = function ($sql, $params = []) use ($pdo) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchColumn();
        };
        $__live = [
            'districts'    => (int)$__q("SELECT COUNT(*) FROM districts"),
            'barangays'    => (int)$__q("SELECT COUNT(*) FROM barangays"),
            'beneficiaries'=> (int)$__q("SELECT COUNT(*) FROM beneficiaries"),
            'approved'     => (int)$__q("SELECT COUNT(*) FROM beneficiaries WHERE status='approved'"),
            'cash_given'   => (float)$__q("SELECT COALESCE(SUM(amount),0) FROM distribution_logs WHERE assistance_type='cash'"),
            'food_left'    => (float)$__q("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE assistance_type='food_pack'"),
        ];
    } catch (Throwable $e) {
        $__live = null;
    }
}

// ---- Knowledge base: every screen / feature of the system. The longest
//      matched key wins, so specific questions beat generic ones. ----
$__chatFaq = [
    [
        'keys' => ['what is this app', 'about this app', 'about the system', 'what is this system', 'what is ayuda', 'what can you do', 'what does this app', 'features', 'tell me about', 'what can this do', 'what can this app do', 'what can the app do', 'what can i do with this', 'how does this app work', 'app guide', 'user guide', 'purpose', 'what is the purpose', 'why does this system exist', 'who made this', 'who created this', 'developer', 'is this app free', 'is this free', 'official website', 'this website', 'website for', 'what browser', 'browser should i use', 'on mobile', 'phone version'],
        'a' => "This is the Ayuda Monitoring System for Davao City, the official tool used by the CSWDO. Residents don't register or log in — they open the public \"View Ayuda Updates\" page, pick their District then Barangay, and see announcements, the distribution schedule (venue, date, what to bring) and the published final list of who will receive ayuda.\n\nIts purpose is to keep the help transparent: the right families receive the right aid, stock and cash are tracked, and every payout is recorded and reported.\n\nBarangay Staff post announcements, publish the final list and distribution notice, file emergency reports, and message the District Admin. The District Admin manages the official beneficiary list, inventory, distribution, programs, reports, accounts, and the audit log.\n\nThe app is provided free to the public for viewing updates — no purchase or payment is ever required for help.\n\nAsk me about any part — updates, announcements, beneficiaries, import/export, final list, distribution, inventory, monitoring, reports, map, calendar, messages, programs, or accounts.\n\nThe app runs in any modern browser — desktop or mobile — with no separate app to install."
    ],
    [
        'keys' => ['cswdo', 'what is cswdo', 'social welfare', 'city social welfare', 'who runs the program', 'csdwo'],
        'a' => "CSWDO stands for the City Social Welfare and Development Office — the Davao City government office that runs the Ayuda program. They hold the official beneficiary list, manage the inventory of food packs / cash / hygiene kits, and conduct the actual distribution with barangay staff helping on-site. In this app, the CSWDO is represented by the District Admin account."
    ],
    [
        'keys' => ['which district', 'what district am i', 'what is my district', 'which barangay am i', 'what is my barangay', 'how do i know my barangay', 'find my barangay', 'my barangay', 'my district', 'list of districts', 'list of barangays', 'districts', 'how many barangays', 'how many districts', 'all barangays'],
        'a' => ($__live ? "The app covers the districts of Davao City (currently Poblacion, Agdao, and Talomo), with " . (int)$__live['barangays'] . " barangays in the system." : "The app covers the districts of Davao City (currently Poblacion, Agdao, and Talomo) and their barangays.") . " The district and barangay structure is fixed in the system — districts and barangays can't be added or removed from the app; changes go through the CSWDO.\n\nOn the public \"View Ayuda Updates\" page you pick your District first, then your Barangay — that automatically shows you only the info for your area.\n\nIf you're barangay staff, your assigned barangay (and its district) is displayed in the header next to your name. If you're not sure which barangay you belong to, any Barangay Hall or the CSWDO can confirm it for you."
    ],
    [
        'keys' => ['how many beneficiaries', 'total beneficiaries', 'how many approved', 'number of beneficiaries', 'population', 'how much cash', 'cash given out', 'given out', 'how many food packs', 'food packs left', 'how many hygiene', 'stock left', 'remaining stock', 'how much is distributed', 'statistics', 'current numbers', 'live numbers', 'totals'],
        'a' => $__live
            ? "The system keeps live totals of beneficiaries, approved members, barangays, cash already distributed, and the remaining food pack stock. Open the Dashboard (admin) to see the current citywide numbers."
            : "The system keeps live totals of beneficiaries, approved members, barangays, cash already distributed, and the remaining food pack stock. Open the Dashboard (admin) to see the current citywide numbers.",
    ],
    [
        'keys' => ['no login', 'no account', 'don\'t need an account', 'beneficiary id', 'gmail', 'login id', 'my id', 'username for', 'do i need an account', 'do i need to log in'],
        'a' => "As a beneficiary you don't need an account, password, or Gmail. Just open \"View Ayuda Updates\" on the homepage, select your District, then your Barangay, and you'll see all announcements, schedules and the final list for your barangay."
    ],
    [
        'keys' => ['register', 'sign up', 'how do i register', 'how to register', 'self register', 'become a beneficiary', 'how to join the list'],
        'a' => "Residents don't self-register — the beneficiary list is official and managed by staff. To be included, inquire at your Barangay Hall or the CSWDO office; staff add and approve records from the official master list.\n\nThe only self-registration inside the app is for Barangay Staff accounts (see \"apply as staff\")."
    ],
    [
        'keys' => ['apply staff', 'apply as staff', 'staff registration', 'become a staff', 'register staff', 'new staff account', 'join as staff', 'staff applicant', 'employment', 'apply to be staff', 'apply to staff', 'be staff', 'can i apply', 'become staff', 'approval take', 'how long', 'how long does', 'approval time', 'when will my application', 'application status'],
        'a' => "Anyone can apply to become Barangay Staff by tapping \"Register Staff\" on the login screen: enter your name, pick a district and barangay, set a password, and submit. The District Admin approves or rejects the application from Manage Users — only approved staff can sign in. How long approval takes depends on the District Admin / CSWDO; check your application status there or follow up through the office. District Admin accounts cannot be self-registered; they are created by an existing District Admin."
    ],
    [
        'keys' => ['login', 'log in', 'sign in', 'can\'t login', 'cannot login', 'login screen', 'username', 'password wrong', 'how do i log in', 'two accounts', 'multiple accounts', 'second account', 'phone number as username', 'phone as username', 'change my username', 'change username'],
        'a' => "Staff and Admin: enter your username and password on the login page, and for barangay staff, select your district and barangay. The system auto-detects whether it's a staff/Admin or beneficiary username. Usernames are set by the system/office (not your phone number); one account per person is the intended setup. Residents don't log in at all — they use \"View Ayuda Updates\"."
    ],
    [
        'keys' => ['forgot password', 'reset password', 'lost password', 'change password', 'forgot', 'set a new password', 'new password', 'reset a password', 'reset an account password', 'staff password'],
        'a' => "If you forgot your password, the District Admin can reset it under Manage Users. You can also change your own password in Profile / Settings (you must know your current password). Beneficiaries don't have passwords — they use the public updates viewer."
    ],
    [
        'keys' => ['role', 'roles', 'barangay staff', 'district admin', 'who is admin', 'who is staff', 'difference between', 'access', 'permissions', 'who can'],
        'a' => "There are two login roles:\n- Barangay Staff: assist their own barangay — post announcements, publish the final list / notice, file emergency reports, and message the District Admin.\n- District Admin (CSWDO): holds the official beneficiary list, manages inventory, runs distribution, creates programs, reviews emergency reports, approves staff accounts, and reads the audit log.\nResidents (the public) don't log in — they use the public updates viewer."
    ],
    [
        'keys' => ['dashboard', 'home page', 'home screen', 'stats', 'summary', 'welcome', 'numbers on home', 'overview numbers'],
        'a' => "The Dashboard is the home screen. The District Admin sees citywide numbers — total beneficiaries, cash (General + Emergency Fund) and food stock, amounts distributed, pending emergencies — plus a beneficiary-by-district chart and recent distribution activity. Barangay Staff see their barangay's callout tiles (pending/approved/rejected requests), the latest announcements, their next upcoming distribution, and recent activity for their barangay. \"Recent Activity\" lists the latest distribution runs; tap one to see the named recipients."
    ],
    [
        'keys' => ['beneficiaries', 'beneficiary list', 'browse', 'category', 'list of beneficiaries', 'where is the list', 'members', 'search beneficiaries', 'add a beneficiary', 'add beneficiary', 'add a record', 'add member', 'delete a beneficiary', 'remove beneficiary', 'delete record', 'can i delete'],
        'a' => "The Beneficiaries screen shows the official list — the District Admin can browse any district/barangay; barangay staff are locked to their own barangay. Pick District then Barangay, filter by category (Senior Citizen, Solo Parent, PWD, Indigent, Student), or type a name in search. From here you can add a beneficiary, open any record to approve/return/reject or edit, import from a spreadsheet, and export the list as CSV or Excel.\n\nA note on deleting: records can't be outright deleted — wrong entries are corrected by staff (edit, Return, or Reject) or fixed directly with the CSWDO, so the official trail stays intact."
    ],
    [
        'keys' => ['status', 'pending', 'approved', 'returned', 'rejected', 'eligibility', 'qualify', 'who qualifies', 'what does pending mean', 'what does approved mean'],
        'a' => "Each beneficiary record has a status:\n- Pending: submitted, waiting for staff review.\n- Approved: verified and eligible for ayuda.\n- Returned: needs more information or a correction — staff return it to fix.\n- Rejected: not eligible.\nOnly Approved beneficiaries appear on a published final list. Categories are Senior Citizen, Solo Parent, PWD, Indigent, and Student."
    ],
    [
        'keys' => ['approve', 'how to approve', 'verification', 'verify', 'approving', 'approve a beneficiary', 'how to approve a beneficiary'],
        'a' => "Barangay Staff: open Beneficiaries, pick the barangay, and open the pending record (or tap Approve). Review the details against the official master list, then tap Approve — or Return if documents need fixing, or Reject if not eligible. Approval can be done from the list or from the beneficiary's detail page. Once Approved, the beneficiary can appear on the published final list."
    ],
    [
        'keys' => ['duplicate', 'possible duplicate', 'same name', 'double record', 'warning icon', 'flagged', 'duplicate record'],
        'a' => "The system automatically flags a possible duplicate when the same name already exists in the same barangay — this is checked both during import and when adding a beneficiary. It is a warning so staff can catch double-records; staff review it and decide to keep, merge, or remove."
    ],
    [
        'keys' => ['import', 'upload', 'csv', 'excel', 'spreadsheet', 'xlsx', 'xls', 'bulk add', 'import beneficiaries', 'add many'],
        'a' => "To add many beneficiaries at once, open the Beneficiaries screen and tap \"Import (CSV / Excel)\". Upload your .csv / .xlsx file and the Import screen shows a preview organized by category, with every row checked for duplicate names against existing records. Review, edit, skip or delete rows, then confirm. Imported records start as Pending for staff review. Rows with missing names, ages, categories, or unmatched district/barangay are skipped automatically."
    ],
    [
        'keys' => ['export', 'download csv', 'download excel', 'download the list', 'save as', 'get a copy of the list'],
        'a' => "On the Beneficiaries screen, tap \"Export (CSV / Excel)\" and choose CSV or Excel. It downloads the currently filtered list — the barangay, category, and search you've applied are honored."
    ],
    [
        'keys' => ['final list', 'publish final list', 'post final list', 'distribution notice', 'publish a list', 'create a notice', 'post a schedule', 'is my name on the list', 'check the final list', 'my name', 'am i included', 'i am included', 'am i on the list', 'included in the list', 'pay out', 'payout', 'how do i check if', 'pwd section', 'section in the list', 'category section', 'who is included', 'who will receive'],
        'a' => "Barangay Staff publish the final list under \"Post Final List / Notice\" (burger menu): create a distribution notice with the title, venue, schedule date/time and what-to-bring, then tap Publish. Publishing generates the final list from that barangay's Approved beneficiaries (grouped by category — Senior Citizen, Solo Parent, PWD, etc.), and it appears on the public updates page (\"View Ayuda Updates\") so residents can search it. The District Admin can review all published schedules. If your name isn't on it, additions only happen through the official list — contact your Barangay Hall."
    ],
    [
        'keys' => ['view updates', 'view my updates', 'public page', 'check my updates', 'updates page', 'view ayuda updates', 'how do i see', 'for residents', 'paano ko makita', 'aking updates', 'makita ang updates', 'paano tingnan ang updates', 'tingnan ang updates'],
        'a' => "Residents use \"View Ayuda Updates\" — the button on the homepage, no login needed. Steps: 1) tap \"View Ayuda Updates\", 2) pick your District, 3) pick your Barangay. You'll see citywide and barangay announcements, your barangay's distribution schedule (venue, date, what to bring), and the published final list. Search your name there to confirm you're included."
    ],
    [
        'keys' => ['claim', 'how to claim', 'receive', 'get ayuda', 'pick up', 'where do i go', 'when will i', 'scheduled date', 'distribution day', 'what to bring', 'valid id', 'barangay clearance', 'mag claim', 'paano mag claim', 'saan mag claim', 'kailan ang schedule', 'bring anything', 'what do i need on the day', 'what time', 'event time', 'claim more than once', 'how many times', 'venue of', 'where is the venue'],
        'a' => "If your name is on the published final list for your barangay, go to the venue shown on the notice on the scheduled date and time. Bring a valid ID plus anything listed under \"What to Bring\" (for example a barangay clearance for some programs). Barangay Staff assist on-site and the District Admin records the distribution. How many times you can receive depends on each program — the notice/final list defines who is included, so check it or ask your Barangay Hall. To confirm the venue and time, open \"View Ayuda Updates\" and pick your barangay."
    ],
    [
        'keys' => ['distribute', 'distribution', 'how to distribute', 'release', 'give ayuda', 'aid type', 'how does distribution work'],
        'a' => "Distribution is run by the District Admin from the Distribution screen: pick District, then Barangay, then Aid Type (Cash - General Fund or Emergency Fund, Food Packs, or Relief Goods). Stock cards show how much remains across all districts. The admin confirms the final list (choose a category or tick individual members), enters the quantity or cash amount, reviews it in the confirmation modal, and submits — one submission distributes to every member on the confirmed list. Records then appear on staff dashboards and Monitoring."
    ],
    [
        'keys' => ['cash', 'food pack', 'food packs', 'hygiene kit', 'hygiene', 'relief goods', 'assistance type', 'types of ayuda', 'what aid is given'],
        'a' => "The system tracks three main aid types: Cash (split into a General Fund and an Emergency Fund, each with its own balance), Food Packs (units), and Relief Goods (units). Inventory, distribution, and reports are organized around these types, and a Distribution Program can use Cash, Food Pack, or Other."
    ],
    [
        'keys' => ['inventory', 'stock', 'add stock', 'replenish', 'restock', 'source', 'low stock', 'how many left', 'remaining stock', 'items available'],
        'a' => "The Inventory screen (District Admin) tracks stock by aid type using tabs: Overview, Food Pack, Cash - General Fund, and Cash - Emergency Fund, plus Relief Goods. \"Add Stock\" increases the selected line's quantity and records the source (DSWD, CSWDO, City Government, LGU, NGO, etc.) in the transaction history. Cash is tracked as a budget amount per fund. Distribution automatically deducts stock, and low stock is highlighted. Barangay Staff do not manage stock."
    ],
    [
        'keys' => ['reserve', 'release stock', 'set aside', 'hold stock', 'reserved', 'return to stock', 'unreserve'],
        'a' => "Inventory lines support Reserve and Release: \"Reserve\" sets aside a quantity for a planned distribution (moving it out of the available amount), and \"Release\" returns it to stock if the plan doesn't push through. Both steps are recorded in the item's history."
    ],
    [
        'keys' => ['monitoring', 'recent activity', 'who received', 'history of distribution', 'distribution record', 'who got', 'events', 'distribution events'],
        'a' => "Monitoring (the dashboard's \"Recent Activity\") lists every distribution run with the item, barangay, number of recipients, and value. Tap an event to expand it and see exactly which beneficiaries received the aid and how much each got — the official \"who received what\" record."
    ],
    [
        'keys' => ['report', 'reports', 'generate report', 'export report', 'preview', 'pdf', 'excel report', 'monthly', 'summary', 'generate'],
        'a' => "Report generation is a District Admin (CSWDO) feature - Barangay Staff only assist on-site, so the tab is hidden for them. Reports produces official summaries: Distribution (sorted by barangay), Barangay and District distribution reports, Inventory, Duplicates / verification, and a Distribution Summary. Pick a report type, filter by category, aid type, date range, district, or barangay, tap \"Generate\" for a preview, then \"Export\" to download it as Excel or PDF for submission."
    ],
    [
        'keys' => ['map', 'barangay map', 'locations', 'where are barangays', 'markers', 'geo', 'coordinates', 'all barangays', 'see all barangays', 'one screen'],
        'a' => "The Map screen is District Admin only. It shows Davao's barangays on a map with a marker per barangay; select a district or barangay to filter, or view all barangays at once. Each marker shows the beneficiary count, approved count, and how much has been distributed. Residents use the public updates viewer instead."
    ],
    [
        'keys' => ['calendar', 'distribution calendar', 'upcoming', 'schedule', 'date', 'month', 'distribution schedule'],
        'a' => "The Distribution Calendar shows all distribution programs (scheduled or ongoing) in a month view with arrows to move between months. Each day lists the program's title, barangay, time, and venue. It also feeds the \"next distribution\" card on staff dashboards."
    ],
    [
        'keys' => ['message', 'messages', 'chat', 'chatting', 'contact', 'inbox', 'unread', 'talk to', 'reply', 'messenger'],
        'a' => "Messages is the internal chat between barangay staff and the District Admin. Staff can message the District Admin as soon as they sign in; the admin sees a list of all barangay staff. The red number on the Messages item (inside the burger menu) and the header badge count unread messages."
    ],
    [
        'keys' => ['announcement', 'announcements', 'post announcement', 'notify', 'notification', 'banner', 'relay', 'citywide', 'barangay scope', 'post a notice', 'announce to', 'senior citizens', 'for senior', 'update for', 'edit an announcement', 'delete an announcement', 'remove announcement', 'announcement photo', 'add a photo', 'picture', 'attach a picture', 'add a picture'],
        'a' => "Barangay Staff post announcements for their barangay (title plus message); mark High priority for urgent notices, and the announcement reaches everyone viewing their barangay on the public page. The District Admin can post citywide announcements and relay or remove them. You can edit or delete your own announcements, and re-open past ones to confirm what's live. High-priority items get an alert style with residents. Announcements appear on the public updates page and on staff dashboards. (Posting a distribution notice is separate — that's under \"Post Final List / Notice\".)"
    ],
    [
        'keys' => ['emergency', 'barangay request', 'request aid', 'fire', 'flood', 'landslide', 'earthquake', 'typhoon', 'urgent', 'shortage', 'need help', 'incident', 'reporting an incident', 'seek help', 'assistance needed', 'request declined', 'request is declined', 'declined request', 'check my request', 'check requests', 'my requests', 'close a request', 'close request', 'follow up request', 'respond to a request', 'status of my request'],
        'a' => "Barangay Staff file emergency / incident reports from the Emergency tab: pick the type (fire, flood, landslide, earthquake, etc.), severity (High for urgent), estimated affected persons, the barangay location, and the help needed. It goes straight to the District Admin / CSWDO, who sees an urgent banner alert and responds from the same screen — Pending to Approved or Declined — and can follow up via Messages. The red badge counts reports still awaiting a response; request status is shown on the Emergency screen (tap a report to see its details and updates)."
    ],
    [
        'keys' => ['program', 'programs', 'distribution program', 'create program', 'assign beneficiaries', 'mark received', 'progress', 'program progress'],
        'a' => "Programs (District Admin) groups a real distribution: create a program with title, assistance type, date/time, and venue, and optionally assign beneficiaries at creation. During distribution, mark each beneficiary Received — the system updates the official distribution record and deducts inventory automatically so program progress matches real stock. Completion percentage and upcoming programs show on the admin dashboard."
    ],
    [
        'keys' => ['audit', 'audit log', 'log', 'logs', 'history', 'trail', 'who did', 'track changes', 'activity log'],
        'a' => "The Audit Log (District Admin only) is a read-only trail of important actions — logins, distributions, beneficiary approvals, inventory changes, account creation, and emergency responses — with the actor's name, role, and timestamp. Filter by action type or date range to see who did what and when."
    ],
    [
        'keys' => ['manage users', 'create account', 'add staff', 'add admin', 'approve staff', 'approve user', 'new account', 'activate account', 'pending approval', 'reject staff', 'deactivate'],
        'a' => "Manage Users (District Admin only, inside the burger menu) lets the admin create new Barangay Staff or District Admin accounts, approve or reject staff applications, reset passwords, and enable or disable accounts. Only a District Admin can create or approve accounts — this keeps sign-ups secure."
    ],
    [
        'keys' => ['profile', 'settings', 'change photo', 'photo', 'change password', 'profile picture', 'update profile', 'my name'],
        'a' => "Profile / Settings lets you update your photo and change your password (verify your current password first). Keep your password private and never share your login."
    ],
    [
        'keys' => ['help center', 'support', 'talk to someone', 'real person', 'human', 'call cswdo', 'contact cswdo', 'office number', 'contact the office', 'kanino ko itatanong', 'sino ang tatanungin'],
        'a' => "I can only explain how the app works — I'm not a live agent. For anything specific to your record or case, visit your Barangay Hall or contact the CSWDO office directly. Staff and the District Admin can also reach each other anytime through the Messages screen inside the app."
    ],
    [
        'keys' => ['sounding board', 'what is sounding board', 'what does sounding board mean', 'sounding board'],
        'a' => "Sounding Board is the name of this chat assistant — the floating circular button labeled \"Sounding Board\" in the bottom corner opens me. Ask me anything about how the Ayuda Monitoring System works, or tap one of the quick questions."
    ],
    [
        'keys' => ['chatbot', 'who are you', 'assistant', 'ayuda assistant', 'what can you help', 'are you a ai', 'are you human'],
        'a' => "I'm the Ayuda Assistant built into this system. I can explain how the app works: the public updates page, announcements, beneficiaries, import/export, final list, distribution, inventory, monitoring, reports, map, calendar, messages, emergency reports, programs, accounts, and security. I only answer questions about this system — for anything else, please ask your Barangay Hall or District Admin."
    ],
    [
        'keys' => ['terms', 'condition', 'privacy', 'data', 'confidential', 'legal', 'policy', 'usage policy', 'personal information', 'who can see', 'see my information', 'my information shared', 'safe to use', 'is the app safe', 'secure', 'security of my data'],
        'a' => "The Terms & Conditions (link near the login page) cover data privacy, accuracy of beneficiary information, and that records are kept confidential and used only for Ayuda distribution. Access is limited: only authorized Barangay Staff and the District Admin can view records within their scope, and the audit log records who did what. Contact the CSWDO if you have concerns about your data."
    ],
    [
        'keys' => ['logout', 'sign out', 'log off', 'exit account'],
        'a' => "Open the menu button in the header, then tap the red Logout button at the bottom of the More sheet. Sign out when using a shared device so your barangay's information stays private."
    ],
    [
        'keys' => ['red dot', 'red badge', 'badge', 'notification icon', 'bell icon'],
        'a' => "The red numbers are live badges. The badge on the Emergency tab counts emergency reports still awaiting a response (own barangay for staff, all barangays for the admin). The number on the Messages item in the burger menu counts unread messages. Both update automatically without reloading the page."
    ],
    [
        'keys' => ['error', 'not working', 'bug', 'blank page', 'problem', 'broken', 'issue with the app'],
        'a' => "If a page won't load or looks wrong, try refreshing first. If it persists, report it to your Barangay Hall or District Admin — for account or data issues the District Admin can help from Manage Users. I can't fix technical faults myself, but I can explain how each screen is supposed to work."
    ],
];

// Words that signal the question is about this app; anything without at
// least one of these is treated as off-topic and not answered.
$__chatOnTopic = [
    'ayuda', 'app', 'system', 'dashboard', 'home', 'beneficiar', 'beneficiary', 'beneficiaries',
    'register', 'registration', 'login', 'log in', 'sign in', 'sign up', 'account', 'gmail', 'password',
    'status', 'pending', 'approved', 'approve', 'returned', 'rejected', 'eligib', 'qualify', 'duplicate',
    'distribut', 'release', 'claim', 'receive', 'pick up', 'final list', 'schedule', 'venue', 'bring',
    'map', 'update', 'updates', 'announcement', 'notification', 'terms', 'condition', 'privacy', 'data',
    'inventory', 'stock', 'reserve', 'report', 'export', 'import', 'csv', 'excel', 'pdf',
    'manage users', 'staff', 'admin', 'barangay', 'district', 'program', 'audit', 'log',
    'emergency', 'shortage', 'request', 'incident', 'fire', 'flood', 'landslide', 'earthquake',
    'monitoring', 'recent', 'recipient', 'calendar', 'message', 'messages', 'chat', 'profile', 'settings',
    'logout', 'sign out', 'role', 'feature',
    'chatbot', 'assistant', 'hello', 'hi', 'hey', 'kumusta', 'help', 'id', 'badge', 'error', 'bug',
    'name', 'list', 'category', 'senior', 'solo', 'pwd', 'indigent', 'student', 'view',
    'included', 'include', 'pay', 'payout', 'declined', 'decline', 'paano', 'kailan', 'saan', 'tingnan',
    'cash', 'budget', 'fund', 'phone', 'mobile', 'browser', 'device', 'website', 'cswdo',
    'support', 'office', 'venue', 'bring', 'count', 'total', 'remaining', 'given', 'approval',
    'food', 'hygiene', 'pack', 'packs', 'kit', 'kits', 'left', 'sounding', 'board',
    'picture', 'pictures', 'notice', 'notices', 'username', 'safe', 'security', 'kanino',
    'second', 'multiple', 'person', 'contact', 'purpose', 'official', 'event', 'photo'
];

$__chatGreetings = [
    'public' => "Hi! I'm the Ayuda Assistant. Ask me about this system — how to view updates, check the final list, when and where to claim ayuda, or how the app works. No login needed.",
    'beneficiary' => "Hi! I'm the Ayuda Assistant. Ask me anything about the system — how to view updates, check the final list, when and where to claim ayuda, or what to bring on distribution day.",
    'staff' => "Hi! I'm the Ayuda Assistant. Ask me about posting announcements, publishing the final list, filing emergency reports, messaging the District Admin, or any other screen in this system.",
    'admin' => "Hi! I'm the Ayuda Assistant. Ask me about managing beneficiaries, inventory, distribution, programs, emergency reports, accounts, reports, or any other screen in this system.",
];

$__chatQuicks = [
    'public' => ["How do I view my updates?", "Is my name on the final list?", "When and where do I claim ayuda?", "Do I need an account or login?"],
    'beneficiary' => ["How do I view my updates?", "Is my name on the final list?", "When and where do I claim ayuda?", "What should I bring on distribution day?"],
    'staff' => ["How do I post announcements?", "How do I file an emergency report?", "How do I publish the final list?", "How do I message the District Admin?"],
    'admin' => ["How do I distribute ayuda to a barangay?", "How do I add stock in Inventory?", "How do I approve barangay staff?", "How do I generate a report?"],
];

$__chatAiEnabled = (defined('GROQ_API_KEY') && GROQ_API_KEY !== '') || (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '');
$__chatAiLabel   = (defined('GROQ_API_KEY') && GROQ_API_KEY !== '') ? 'Powered by Groq AI — I can answer anything' : ((defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') ? 'Powered by Google Gemini — I can answer anything' : '');

$__chatData = [
    'role' => $__chatRole,
    'greeting' => $__chatGreetings[$__chatRole],
    'quick' => $__chatQuicks[$__chatRole],
    'offTopic' => $__chatOffTopic,
    'fallback' => $__chatFallback,
    'onTopic' => $__chatOnTopic,
    'faq' => $__chatFaq,
    'apiUrl' => $__assetPrefix . 'chatbot_api.php',
    'csrf' => generateCSRFToken(),
    'aiEnabled' => $__chatAiEnabled,
];
?>
<style>
.chatbot-fab {
    position: fixed; bottom: 56px; right: 60px; z-index: 40;
    width: 54px; height: 54px; border-radius: 50%;
    background: var(--navy); color: var(--gold);
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; border: none; cursor: pointer;
    box-shadow: 0 6px 18px rgba(11,46,74,0.4);
    overflow: hidden; padding: 0;
    animation: chatbot-pulse 1.8s ease-in-out infinite;
}
.chatbot-fab::after {
    content: ""; position: absolute; inset: 0; border-radius: 50%;
    box-shadow: 0 0 0 0 rgba(255,255,255,0.28);
    animation: chatbot-ring 2.2s ease-out infinite;
    pointer-events: none;
}
.chatbot-fab img { width: 100%; height: 100%; object-fit: cover; object-position: center; display: block; }
.chatbot-fab svg { width: 26px; height: 26px; }
.chatbot-fab:hover { background: var(--navy-light); }
.chatbot-fab:active { transform: scale(.97); }
.chatbot-fab .chatbot-bubble {
    position: absolute;
    bottom: calc(100% + 10px);
    left: 50%;
    transform: translateX(-50%) translateY(4px);
    background: var(--navy);
    color: #fff;
    padding: 6px 10px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    box-shadow: 0 6px 16px rgba(11,46,74,0.22);
    border: 1px solid rgba(255,255,255,0.12);
    animation: chatbot-bubble 2.6s ease-in-out infinite;
    opacity: 1;
    transition: opacity .2s ease, transform .2s ease, visibility .2s ease;
    z-index: 60;
    pointer-events: none;
}
.chatbot-fab .chatbot-bubble::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 50%;
    right: auto;
    width: 8px; height: 8px;
    background: var(--navy);
    transform: translateX(-50%) rotate(45deg);
    border-right: 1px solid rgba(255,255,255,0.12);
    border-bottom: 1px solid rgba(255,255,255,0.12);
}
.chatbot-fab.hide-bubble .chatbot-bubble {
    opacity: 0 !important; visibility: hidden; pointer-events: none;
    animation: none;
}
@keyframes chatbot-bubble {
    0%, 80%, 100% { opacity: 0; transform: translateX(-50%) translateY(4px); }
    10%, 70%      { opacity: 0; transform: translateX(-50%) translateY(0); }
}
@keyframes chatbot-pulse {
    0%, 100% { transform: scale(1); }
    50%      { transform: scale(1.08); }
}
@keyframes chatbot-ring {
    0%   { box-shadow: 0 0 0 0 rgba(255,255,255,0.28); opacity: .9; }
    70%  { box-shadow: 0 0 0 9px rgba(255,255,255,0); opacity: 0; }
    100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); opacity: 0; }
}
.chatbot-panel {
    position: fixed; inset: 0; z-index: 200;
    width: 100%; height: 100%; max-height: none;
    background: var(--surface);
    border-radius: 0; box-shadow: none;
    display: none; flex-direction: column; overflow: hidden;
    border: none;
}
.chatbot-panel.show { display: flex; }
.chatbot-head {
    background: var(--navy);
    color: #fff; padding: 16px 18px;
    padding-top: max(16px, env(safe-area-inset-top));
    display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.chatbot-head strong { font-size: 15px; }
.chatbot-head span { font-size: 12px; color: #b9c9da; display: block; }
.chatbot-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    margin-right: 10px; background: var(--gold);
    display: flex; align-items: center; justify-content: center;
    color: var(--navy); font-weight: 700; font-size: 14px;
    box-shadow: 0 0 0 0 rgba(255,210,46,0.4);
    animation: chatbot-avatar-pulse 1.8s ease-in-out infinite;
    flex-shrink: 0; overflow: hidden;
}
.chatbot-avatar img { width: 100%; height: 100%; object-fit: cover; }
.chatbot-avatar svg { width: 18px; height: 18px; }
@keyframes chatbot-avatar-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(255,210,46,0.35); transform: scale(1); }
    50%      { box-shadow: 0 0 0 4px rgba(255,210,46,0); transform: scale(1.06); }
}
.chatbot-close { background: none; border: none; color: #fff; font-size: 24px; cursor: pointer; line-height: 1; }
.chatbot-body { flex: 1; overflow-y: auto; padding: 16px; background: var(--bg); }
.chatbot-msg { max-width: 85%; margin-bottom: 10px; font-size: 14px; line-height: 1.45; padding: 10px 14px; border-radius: 14px; white-space: pre-line; }
.chatbot-msg.bot { background: var(--surface); border: 1px solid var(--line); color: var(--text); border-bottom-left-radius: 3px; }
.chatbot-msg.user { background: var(--navy); color: #fff; margin-left: auto; border-bottom-right-radius: 3px; }
.chatbot-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 16px 12px; background: transparent; flex-shrink: 0; }
.chatbot-chip {
    font-size: 12.5px; padding: 7px 12px; border-radius: 14px; background: var(--surface);
    border: 1px solid var(--line); color: var(--navy); cursor: pointer;
}
.chatbot-chip:hover { border-color: var(--navy); background: var(--surface-2); }
.chatbot-input-row { display: flex; gap: 8px; padding: 12px 16px; padding-bottom: max(12px, env(safe-area-inset-bottom)); border-top: 1px solid var(--line); flex-shrink: 0; background: var(--surface); }
.chatbot-input-row input {
    flex: 1; height: 42px; border-radius: 10px; border: 1px solid var(--line-strong);
    padding: 0 14px; font-size: 14px;
}
.chatbot-input-row input:focus { outline: none; border-color: var(--navy); }
.chatbot-send { width: 42px; height: 42px; border-radius: 10px; background: var(--navy); color: #fff; border: none; cursor: pointer; flex-shrink: 0; }

@media (max-width: 560px) {
    .chatbot-fab { bottom: 62px; right: 54px; width: 48px; height: 48px; font-size: 20px; }
}
</style>

<button class="chatbot-fab" id="chatbotFab" onclick="toggleChatbot()" aria-label="Help chat" style="position:fixed;overflow:hidden;">
    <?php if ($__chatbotIconFile): ?>
        <img src="<?= e($__chatbotIconFile) ?>" alt="Chat" style="border-radius:50%;width:100%;height:100%;object-fit:cover;object-position:center;display:block;">
    <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 12C4 7.58172 7.58172 4 12 4C16.4183 4 20 7.58172 20 12C20 16.4183 16.4183 20 12 20C10.6117 20 9.30341 19.6461 8.16473 19.0234L4 20L5.13509 16.6303C4.41519 15.2851 4 13.6884 4 12Z"
                  fill="var(--gold)" stroke="var(--gold)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="8.5" cy="12" r="1.15" fill="var(--navy)"/>
            <circle cx="12" cy="12" r="1.15" fill="var(--navy)"/>
            <circle cx="15.5" cy="12" r="1.15" fill="var(--navy)"/>
        </svg>
    <?php endif; ?>
    <span class="chatbot-bubble">Ask now</span>
</button>

<div class="chatbot-panel" id="chatbotPanel">
    <div class="chatbot-head">
        <div style="display:flex;align-items:center;">
            <div class="chatbot-avatar" id="chatbotHeadAvatar">
                <?php if ($__chatbotIconFile): ?>
                    <img src="<?= e($__chatbotIconFile) ?>" alt="Assistant">
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 12C4 7.58172 7.58172 4 12 4C16.4183 4 20 7.58172 20 12C20 16.4183 16.4183 20 12 20C10.6117 20 9.30341 19.6461 8.16473 19.0234L4 20L5.13509 16.6303C4.41519 15.2851 4 13.6884 4 12Z"
                              fill="var(--navy)" stroke="var(--navy)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="8.5" cy="12" r="1.15" fill="var(--gold)"/>
                        <circle cx="12" cy="12" r="1.15" fill="var(--gold)"/>
                        <circle cx="15.5" cy="12" r="1.15" fill="var(--gold)"/>
                    </svg>
                <?php endif; ?>
            </div>
            <div>
                <strong>Ayuda Assistant</strong>
                <span><?= $__chatAiEnabled ? $__chatAiLabel : 'Ask me about the app' ?></span>
            </div>
        </div>
        <button class="chatbot-close" onclick="toggleChatbot()" aria-label="Close">&times;</button>
    </div>
    <div class="chatbot-body" id="chatbotBody"></div>
    <div class="chatbot-chips" id="chatbotChips"></div>
    <div class="chatbot-input-row">
        <input type="text" id="chatbotInput" placeholder="Type a question..." onkeydown="if(event.key==='Enter')sendChatbotMessage()">
        <button class="chatbot-send" onclick="sendChatbotMessage()" aria-label="Send"><?= svgIcon('right', 18) ?></button>
    </div>
</div>

<script>
var CHAT_DATA = <?= json_encode($__chatData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
(function() {
    var faq = CHAT_DATA.faq;
    var onTopicWords = CHAT_DATA.onTopic;
    var offTopic = CHAT_DATA.offTopic;
    var fallback = CHAT_DATA.fallback;
    var quickQuestions = CHAT_DATA.quick;
    var greeting = CHAT_DATA.greeting;

    var opened = false;

    function normalize(text) {
        return ' ' + (text.toLowerCase()
            .replace(/[\u2018\u2019\u0027]/g, "'")
            .replace(/[\u201C\u201D\u0022]/g, '"')
            .replace(/[^a-z0-9\u00f1 ']/g, ' '))
            .replace(/\s+/g, ' ') + ' ';
    }

    // Plural helpers so "announcements" matches "announcement", "messages"
    // matches "message", etc. Both question and keyword get the same treatment.
    function pluralLess(w) {
        return w.length > 3 && w.charAt(w.length - 1) === 's' ? w.slice(0, -1) : w;
    }
    function pluralize(w) {
        if (w.length > 3 && (w.charAt(w.length - 1) === 's' || w.slice(-1) === 'x')) return w;
        if (w.length > 3 && w.slice(-1) === 'y' && 'aeiou'.indexOf(w.slice(-2, -1)) === -1) return w.slice(0, -1) + 'ies';
        return w + 's';
    }
    function phraseVariants(p) {
        var out = [p];
        var ws = p.split(' ');
        var last = ws[ws.length - 1];
        var rest = ws.slice(0, -1).join(' ');
        if (last.length > 3) {
            var alt = (last.charAt(last.length - 1) === 's') ? pluralLess(last) : pluralize(last);
            out.push(rest ? rest + ' ' + alt : alt);
        }
        return out;
    }
    function findPhraseIdx(lower, phrase) {
        var vars = phraseVariants(phrase);
        for (var i = 0; i < vars.length; i++) {
            var idx = lower.indexOf(' ' + vars[i] + ' ');
            if (idx !== -1) return idx;
        }
        return -1;
    }
    function hasPhrase(lower, phrase) {
        return findPhraseIdx(lower, phrase) !== -1;
    }

    // Words used for scoring that are too generic to beat a specific intent
    // keyword (so "how do I message the District Admin" hits Messages, not Roles).
    var genericTerms = {
        'staff': 1, 'admin': 1, 'district admin': 1, 'barangay staff': 1,
        'account': 1, 'password': 1, 'status': 1, 'role': 1,
        'id': 1, 'name': 1, 'list': 1, 'date': 1, 'category': 1
    };

    window.toggleChatbot = function() {
        var panel = document.getElementById('chatbotPanel');
        var fab = document.getElementById('chatbotFab');
        panel.classList.toggle('show');
        if (fab) fab.classList.toggle('hide-bubble', panel.classList.contains('show'));
        // Always show/hide bubble based on panel state
        var bubble = fab ? fab.querySelector('.chatbot-bubble') : null;
        if (bubble) bubble.style.opacity = panel.classList.contains('show') ? '0' : '1';
        if (panel.classList.contains('show') && !opened) {
            opened = true;
            addMessage('bot', greeting);
            renderChips();
        }
    };

    function addMessage(who, text) {
        var body = document.getElementById('chatbotBody');
        var msg = document.createElement('div');
        msg.className = 'chatbot-msg ' + who;
        msg.textContent = text;
        body.appendChild(msg);
        body.scrollTop = body.scrollHeight;
    }

    function renderChips() {
        var chips = document.getElementById('chatbotChips');
        chips.innerHTML = '';
        quickQuestions.forEach(function(q) {
            var chip = document.createElement('div');
            chip.className = 'chatbot-chip';
            chip.textContent = q;
            chip.onclick = function() { askChatbot(q); };
            chips.appendChild(chip);
        });
    }

    function findAnswer(question) {
        var lower = normalize(question);
        var tokens = lower.split(/\s+/).filter(Boolean).map(pluralLess);
        var tokenSet = {};
        tokens.forEach(function(t) { tokenSet[t] = 1; });

        var isOnTopic = onTopicWords.some(function(w) {
            if (w.indexOf(' ') !== -1) return hasPhrase(lower, w);
            var k = pluralLess(w);
            if (tokenSet[k]) return true;
            // Prefix match so "distribution" counts as "distribut" and
            // "announcements" as "announcement". Only meaningful length words.
            for (var t in tokenSet) {
                if (t.length > 3 && (t.indexOf(k) === 0 || k.indexOf(t) === 0)) return true;
            }
            return false;
        });
        if (!isOnTopic) return offTopic;

        // The most specific match wins: prefer the longest keyword that matches
        // (so "how to publish the final list" beats a generic "list" answer),
        // and among equal lengths prefer the earliest match. Overly generic
        // terms are penalized so a real intent keyword wins over role labels.
        var best = null, score = -1;
        for (var i = 0; i < faq.length; i++) {
            var entry = faq[i];
            for (var j = 0; j < entry.keys.length; j++) {
                var key = entry.keys[j];
                var idx = findPhraseIdx(lower, key);
                if (idx >= 0) {
                    var s = key.length * 1000 - idx - (genericTerms[key] ? 6000 : 0);
                    if (s > score) { score = s; best = entry; }
                }
            }
        }

        // Best-effort: the question was on-topic but no phrase matched exactly.
        // Route it to the topic whose keywords overlap the question the most,
        // so almost any system question still gets a useful answer.
        if (!best) {
            var overlapBest = null, overlapScore = 0;
            for (var i = 0; i < faq.length; i++) {
                var entry = faq[i];
                var hit = 0;
                for (var j = 0; j < entry.keys.length; j++) {
                    var parts = entry.keys[j].split(' ');
                    for (var p = 0; p < parts.length; p++) {
                        var w = parts[p];
                        if (w.length < 4) continue;
                        var k = pluralLess(w);
                        if (tokenSet[k]) hit += 2;
                        else {
                            for (var t in tokenSet) {
                                if (t.length > 3 && (t.indexOf(k) === 0 || k.indexOf(t) === 0)) { hit += 1; break; }
                            }
                        }
                    }
                }
                if (hit > overlapScore) { overlapScore = hit; overlapBest = entry; }
            }
            if (overlapBest && overlapScore >= 2) best = overlapBest;
        }
        return best ? best.a : fallback;
    }

    function askChatbot(question) {
        addMessage('user', question);
        var body = document.getElementById('chatbotBody');
        var typing = document.createElement('div');
        typing.className = 'chatbot-msg bot';
        typing.innerHTML = '<span style="font-size:12px;opacity:.7;">typing&hellip;</span>';
        body.appendChild(typing);
        body.scrollTop = body.scrollHeight;

        function finish(answer) {
            typing.parentNode.removeChild(typing);
            addMessage('bot', answer);
        }

        var payload = {
            question: question,
            role: CHAT_DATA.role,
            _csrf_token: CHAT_DATA.csrf
        };

        fetch(CHAT_DATA.apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json().catch(function () { return null; });
        }).then(function (j) {
            if (j && j.ok && j.answer) finish(j.answer);
            else setTimeout(function () { finish(findAnswer(question)); }, 150);
        }).catch(function () {
            setTimeout(function () { finish(findAnswer(question)); }, 150);
        });
    }

    window.sendChatbotMessage = function() {
        var input = document.getElementById('chatbotInput');
        var val = input.value.trim();
        if (!val) return;
        input.value = '';
        askChatbot(val);
    };
})();
</script>
