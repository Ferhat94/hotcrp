<?php
// batch/authorization_matrix_runner.php
// Generate authorization matrices for each policy
// Tests all user-paper-action combinations for each of the 17 policies

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(GetAuthorizations::run_args($argv));
}

class GetAuthorizations {
    /** @var Conf */
    private $conf;
    private $policies;
    /** @var ?string */
    private $current_policy_name = null;
    
    function __construct(Contact $user) {
        $this->conf = $user->conf;
        
        // Define all policies to test (Baseline + One-Change-At-a-Time)
        // Each policy changes ONE setting while keeping others at baseline
        $this->policies = [
            "author_names_hidden_from_reviewers" => [
                // From settinginfo.json: "author_visibility" -> "sub_blind"
                // values: [0, 1, 2, 3], json_values: ["open", "optional", "blind", "blind_until_review"]
                "open" => 0,                       // TEST: Never hide names
                "optional" => 1,                   // BASELINE (skip) - depends
                "blind" => 2,                      // TEST: Always hide names
                "blind_until_review" => 3          // TEST: Hide until review
            ],
            "author_update_submission" => [
                // From settinginfo.json: "submission_freeze" -> "sub_freeze"
                // values: [0, 1], default_value: 0
                "allow_updates" => 0,              // BASELINE (skip) - allow updates
                "freeze_submissions" => 1          // TEST: Freeze submissions
            ],
            "authors_view_decision" => [
                // From settinginfo.json: "decision_visibility_author" -> "val.au_seedec"
                // values: [0, 2, 1], json_values: ["no", "yes", "condition"]
                // Note: Skipping "condition" option as it requires additional search text setup
                "no" => 0,                         // BASELINE (skip) - authors cannot see decisions
                "yes" => 2                         // TEST: Authors can see decisions
            ],
            "can_view_review_content" => [
                // From settinginfo.json: "review_visibility_pc" -> "viewrev"
                // values: [-1, 0, 1, 3, 4], json_values: ["never", "after_review", "always", "assignment_not_incomplete", "all_assignments_complete"]
                "never" => -1,                     // TEST: Never view reviews
                "after_review" => 0,               // BASELINE (skip) - after review (default_value: 0)
                "always" => 1,                     // TEST: Always view reviews
                "assignment_not_incomplete" => 3,  // TEST: Unless incomplete
                "all_assignments_complete" => 4    // TEST: After completing all assigned reviews
            ],
            "can_view_reviewer_identity" => [
                // From settinginfo.json: "review_identity_visibility_pc" -> "viewrevid"
                // values: [-1, 0, 5, 1], json_values: ["never", "after_review", "if_assigned", "always"]
                "never" => -1,                     // TEST: Never see reviewer names
                "after_review" => 0,               // TEST: After review
                "if_assigned" => 5,                // TEST: If assigned
                "always" => 1                     // BASELINE (skip) - always see reviewer names
            ],
            "pc_can_view_incomplete_submission" => [
                // From settinginfo.json: "draft_submission_early_visibility" -> "pc_seeall"
                // type: "checkbox" (0 or 1)
                "unchecked" => 0,                  // BASELINE (skip) - PC cannot view incomplete
                "checked" => 1                     // TEST: PC can view incomplete
            ],
            "pc_can_view_submitted_pdf" => [
                // From settinginfo.json: "submitted_document_early_visibility" -> "pc_seeallpdf"
                // type: "checkbox" (0 or 1)
                "unchecked" => 0,                  // BASELINE (skip) - PC cannot view PDFs
                "checked" => 1                     // TEST: PC can view PDFs
            ],
            "reviewer_view_comments" => [
                // From settinginfo.json: "comment_visibility_reviewer" -> "cmt_revid"
                // values: [0, 1], json_values: ["if_reviewers_visible", "yes"]
                "if_reviewers_visible" => 0,       // BASELINE (skip) - only when can see reviewer names
                "yes" => 1                         // TEST: Always view comments
            ],
            "reviewers_view_decision" => [
                // From settinginfo.json: "decision_visibility_reviewer" -> "seedec"
                // values: [0, 3, 1], json_values: ["no", "unconflicted", "yes"]
                "no" => 0,                         // BASELINE (skip) - reviewers cannot see decisions
                "unconflicted" => 3,               // TEST: Unless they have a conflict
                "yes" => 1                         // TEST: Reviewers can see decisions
            ]
        ];
    }
    
    function run() {
        echo "Starting get authorizations...\n";
        
        // Load users and papers
        $users = $this->load_users();
        $papers = $this->load_papers();
        
        echo "Loaded " . count($users) . " users and " . count($papers) . " papers\n";
        
        // Create output directory
        $output_dir = "logs/policies";
        if (!is_dir($output_dir)) {
            mkdir($output_dir, 0755, true);
        }
        
        // Also generate a single baseline matrix (no changes) for comparison
        $this->test_baseline($users, $papers, $output_dir);
        
        // Test each policy
        foreach ($this->policies as $policy_name => $options) {
            foreach ($options as $option_name => $value) {
                if ($this->is_baseline_option($policy_name, $option_name)) {
                    continue; // Skip baseline options
                }
                
                $this->test_policy($policy_name, $option_name, $value, $users, $papers, $output_dir);
            }
        }  
        
        echo "Authorization generation complete!\n";
    }

    private function test_baseline($users, $papers, $output_dir) {
        echo "Testing policy: baseline (baseline)\n";
        $this->set_baseline_settings();
        $policy_dir = $output_dir . "/baseline";
        if (!is_dir($policy_dir)) {
            mkdir($policy_dir, 0755, true);
        }
        $this->generate_authorization_matrix("baseline", "baseline", $users, $papers, $policy_dir);
        // Write a minimal policy info for baseline using the complete configuration
        $complete_configuration = $this->get_complete_configuration_for_test("", "");
        $policy_info = [
            "policy" => "baseline",
            "current_option" => "baseline",
            "current_option_description" => "All settings at baseline defaults",
            "what_this_tests" => "Baseline scenario (no changes)",
            "complete_configuration" => $complete_configuration,
            "statistics" => $this->policy_stats ?? [
                "total_combinations" => 0,
                "permit_count" => 0,
                "deny_count" => 0
            ]
        ];
        $json_path = $policy_dir . "/policy.json";
        @file_put_contents($json_path, json_encode($policy_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    private function load_users() {
        $users = [];
        $rs = $this->conf->qe("SELECT contactId FROM ContactInfo");
        while (($row = $rs->fetch_object())) {
            $user = $this->conf->user_by_id((int) $row->contactId);
            $users[] = $user;
        }
        Dbl::free($rs);
        return $users;
    }
    
    private function load_papers() {
        $papers = [];
        $prs = $this->conf->qe("SELECT * FROM Paper");
        while (($prow = PaperInfo::fetch($prs, null, $this->conf))) {
            $papers[] = $prow;
        }
        Dbl::free($prs);
        return $papers;
    }
    
    private function is_baseline_option($policy_name, $option_name) {
        $baseline_options = [
            "author_names_hidden_from_reviewers" => "blind",
            "author_update_submission" => "allow_updates",
            "authors_view_decision" => "no",
            "can_view_review_content" => "after_review",
            "can_view_reviewer_identity" => "always",
            "pc_can_view_incomplete_submission" => "unchecked",
            "pc_can_view_submitted_pdf" => "unchecked",
            "reviewer_view_comments" => "if_reviewers_visible",
            "reviewers_view_decision" => "no"
        ];
        
        return ($baseline_options[$policy_name] ?? "unknown") === $option_name;
    }
    
    private function test_policy($policy_name, $option_name, $value, $users, $papers, $output_dir) {
        echo "Testing policy: $policy_name ($option_name)\n";
        
        // Set baseline settings
        $this->set_baseline_settings();
        
        // Apply the specific policy setting
        $this->set_setting($policy_name, $value);
        
        // Create policy directory
        $policy_dir = $output_dir . "/" . $policy_name . "_" . $option_name;
        if (!is_dir($policy_dir)) {
            mkdir($policy_dir, 0755, true);
        }
        
        // Set current policy context for debugging
        $this->current_policy_name = $policy_name;

        // IMPORTANT: Reload papers after settings change to avoid cached per-paper round state
        $fresh_papers = $this->load_papers();
        // Generate authorization matrix
        $this->generate_authorization_matrix($policy_name, $option_name, $users, $fresh_papers, $policy_dir);
        
        // Write policy info
        $this->write_policy_info($policy_dir, $policy_name, $option_name);

        // Clear current policy context
        $this->current_policy_name = null;
    }
    
    private function set_baseline_settings() {
        // Set all settings to their baseline values (matching simple_policy_runner.php)
        $baseline_settings = [
            "sub_blind" => 2,           // blind (default_value: 2)
            "sub_freeze" => 0,          // allow_updates (default_value: 0)
            "au_seedec" => 0,           // no (default_value: 0)
            "viewrev" => 0,             // after_review (default_value: 0)
            "viewrev_ext" => 0,         // after_review (default_value: 0)
            "viewrevid" => 1,           // always (initial_value: 1)
            "viewrevid_ext" => 0,       // after_review (default_value: 0)
            "pc_seeall" => 0,          // unchecked (checkbox default: 0)
            "pc_seeallpdf" => 0,       // unchecked (checkbox default: 0)
            "cmt_revid" => 0,          // if_reviewers_visible (default_value: 0)
            "seedec" => 0              // no (default_value: 0)
        ];
        
        foreach ($baseline_settings as $setting => $value) {
            $this->conf->save_setting($setting, $value);
        }
        // Recompute derived settings (e.g., _au_seedec) to reflect baseline
        $this->conf->refresh_settings();
        // Invalidate and rebuild user rights caches so permission checks see new settings
        Contact::update_rights();
    }
    
    private function set_setting($policy_name, $value) {
        echo "DEBUG: Setting $policy_name to $value\n";
        switch ($policy_name) {
            case "author_names_hidden_from_reviewers":
                $this->conf->save_setting("sub_blind", $value);
                echo "DEBUG: sub_blind is now: " . $this->conf->setting("sub_blind") . "\n";
                break;
            case "author_update_submission":
                $this->conf->save_setting("sub_freeze", $value);
                echo "DEBUG: sub_freeze is now: " . $this->conf->setting("sub_freeze") . "\n";
                break;
            case "authors_view_decision":
                $this->conf->save_setting("au_seedec", $value);
                echo "DEBUG: au_seedec is now: " . $this->conf->setting("au_seedec") . "\n";
                break;
            case "can_view_review_content":
                $this->conf->save_setting("viewrev", $value);
                $this->conf->save_setting("viewrev_ext", $value);
                echo "DEBUG: viewrev is now: " . $this->conf->setting("viewrev") . "\n";
                echo "DEBUG: viewrev_ext is now: " . $this->conf->setting("viewrev_ext") . "\n";
                break;
            case "can_view_reviewer_identity":
                $this->conf->save_setting("viewrevid", $value);
                $this->conf->save_setting("viewrevid_ext", $value);
                echo "DEBUG: viewrevid is now: " . $this->conf->setting("viewrevid") . "\n";
                echo "DEBUG: viewrevid_ext is now: " . $this->conf->setting("viewrevid_ext") . "\n";
                break;
            case "pc_can_view_incomplete_submission":
                $this->conf->save_setting("pc_seeall", $value);
                echo "DEBUG: pc_seeall is now: " . $this->conf->setting("pc_seeall") . "\n";
                break;
            case "pc_can_view_submitted_pdf":
                $this->conf->save_setting("pc_seeallpdf", $value);
                echo "DEBUG: pc_seeallpdf is now: " . $this->conf->setting("pc_seeallpdf") . "\n";
                break;
            case "reviewer_view_comments":
                $this->conf->save_setting("cmt_revid", $value);
                echo "DEBUG: cmt_revid is now: " . $this->conf->setting("cmt_revid") . "\n";
                break;
            case "reviewers_view_decision":
                $this->conf->save_setting("seedec", $value);
                echo "DEBUG: seedec is now: " . $this->conf->setting("seedec") . "\n";
                break;
        }
        // Ensure digested settings and rights reflect the change immediately
        $this->conf->refresh_settings();
        Contact::update_rights();
    }
    
    private function generate_authorization_matrix($policy_name, $option_name, $users, $papers, $policy_dir) {
        $csv_path = $policy_dir . "/" . $policy_name . "_" . $option_name . ".csv";
        $csv_file = fopen($csv_path, 'w');
        
        // Write CSV header
        fputcsv($csv_file, ['user_id', 'paper_id', 'action', 'decision']);
        
        $total_combinations = 0;
        $permit_count = 0;
        $deny_count = 0;
        
        // Test all user-paper-action combinations
        foreach ($users as $user) {
            foreach ($papers as $paper) {
                // Test the 9 actions, 1:1 with policy groups
                $actions = [
                    'author_names_hidden_from_reviewers',      // can_view_authors
                    'author_update_submission',                 // can_edit_paper
                    'authors_view_decision',                    // can_view_decision
                    'can_view_review_content',                  // can_view_review
                    'can_view_reviewer_identity',               // can_view_review_identity
                    'pc_can_view_incomplete_submission',        // can_view_paper(false)
                    'pc_can_view_submitted_pdf',                // can_view_paper(true)
                    'reviewer_view_comments',                   // can_view_comment
                    'reviewers_view_decision'                   // can_view_decision
                ];
                
                foreach ($actions as $action) {
                    $decision = $this->test_action($user, $paper, $action);
                    fputcsv($csv_file, [$user->contactId, $paper->paperId, $action, $decision]);
                    
                    $total_combinations++;
                    if ($decision === 'Permit') {
                        $permit_count++;
                    } else {
                        $deny_count++;
                    }
                }
            }
        }
        
        fclose($csv_file);
        
        // Store stats for JSON
        $this->policy_stats = [
            'total_combinations' => $total_combinations,
            'permit_count' => $permit_count,
            'deny_count' => $deny_count
        ];
    }
    
    private function test_action($user, $paper, $action) {
        $result = false;
        switch ($action) {
            case 'author_names_hidden_from_reviewers':
                $result = $user->can_view_authors($paper);
                break;
            case 'author_update_submission':
                $result = $user->can_edit_paper($paper);
                // Focused debug: show why edit is Permit/Deny under freeze tests
                if ($this->current_policy_name === 'author_update_submission'
                    && (method_exists($paper, 'has_author') && $paper->has_author($user)
                        || ($user->contactId == 5 && $paper->paperId == 4))) {
                    $sr = $paper->submission_round();
                    $isDraft = $paper->timeSubmitted <= 0 ? 'YES' : 'NO';
                    $freeze = isset($sr->freeze) ? ($sr->freeze ? 'YES' : 'NO') : 'UNK';
                    $rtag = isset($sr->tag) ? $sr->tag : 'unnamed';
                    $runnamed = isset($sr->unnamed) ? ($sr->unnamed ? 'YES' : 'NO') : 'UNK';
                    $tu = method_exists($sr, 'time_update') ? ($sr->time_update(true) ? 'YES' : 'NO') : 'UNK';
                    $isAuthor = method_exists($paper, 'has_author') ? ($paper->has_author($user) ? 'YES' : 'NO') : 'UNK';
                    $sub_freeze = $this->conf->setting('sub_freeze');
                    echo "DEBUG(edit): U {$user->contactId} P {$paper->paperId} author={$isAuthor} draft={$isDraft} round={$rtag} unnamed_round={$runnamed} sub_freeze={$sub_freeze} sr.freeze={$freeze} sr.time_update={$tu} => " . ($result ? 'Permit' : 'Deny') . "\n";
                }
                break;
            case 'authors_view_decision':
                $result = $user->can_view_decision($paper);
                break;
            case 'can_view_review_content':
                $result = $user->can_view_review($paper, null);
                break;
            case 'can_view_reviewer_identity':
                $result = $user->can_view_review_identity($paper);
                break;
            case 'pc_can_view_incomplete_submission':
                $result = $user->can_view_paper($paper, false);
                break;
            case 'pc_can_view_submitted_pdf':
                $result = $user->can_view_paper($paper, true);
                break;
            case 'reviewer_view_comments':
                $comment = new CommentInfo();
                $comment->conf = $this->conf;
                $comment->prow = $paper;
                $comment->paperId = $paper->paperId;
                $comment->commentId = 1;
                $comment->contactId = 999;
                $comment->commentType = CommentInfo::CTVIS_REVIEWER | CommentInfo::CT_TOPIC_PAPER;
                $result = $user->can_view_comment($paper, $comment);
                break;
            case 'reviewers_view_decision':
                $result = $user->can_view_decision($paper);
                break;
            default:
                return 'Deny';
        }
        
        
        return $result ? 'Permit' : 'Deny';
    }
    
    private function write_policy_info($policy_dir, $policy_name, $option_name) {
        $ui_options = [
            "author_names_hidden_from_reviewers" => [
                "open" => "Never hide author names from reviewers",
                "optional" => "Hide author names optionally (baseline)",
                "blind" => "Always hide author names from reviewers",
                "blind_until_review" => "Hide author names until review starts"
            ],
            "author_update_submission" => [
                "allow_updates" => "Allow authors to update submissions (baseline)",
                "freeze_submissions" => "Freeze all submissions"
            ],
            "authors_view_decision" => [
                "no" => "Authors cannot see decisions (baseline)",
                "yes" => "Authors can see decisions"
            ],
            "can_view_review_content" => [
                "never" => "Never view reviews",
                "after_review" => "View reviews only after completing review (baseline)",
                "always" => "Always view reviews",
                "assignment_not_incomplete" => "View reviews unless incomplete",
                "all_assignments_complete" => "View reviews after completing all assigned reviews"
            ],
            "can_view_reviewer_identity" => [
                "never" => "Never see reviewer names",
                "after_review" => "See reviewer names only after review",
                "if_assigned" => "See reviewer names only if assigned",
                "always" => "Always see reviewer names (baseline)"
            ],
            "pc_can_view_incomplete_submission" => [
                "unchecked" => "PC cannot view incomplete submissions (baseline)",
                "checked" => "PC can view incomplete submissions"
            ],
            "pc_can_view_submitted_pdf" => [
                "unchecked" => "PC cannot view submitted PDFs (baseline)",
                "checked" => "PC can view submitted PDFs"
            ],
            "reviewer_view_comments" => [
                "if_reviewers_visible" => "View comments only when can see reviewer names (baseline)",
                "yes" => "Always view comments"
            ],
            "reviewers_view_decision" => [
                "no" => "Reviewers cannot see decisions (baseline)",
                "unconflicted" => "Reviewers can see decisions unless they have a conflict",
                "yes" => "Reviewers can see decisions"
            ]
        ];
        
        // Get the complete configuration for this test (all 9 policies with their chosen options)
        $complete_configuration = $this->get_complete_configuration_for_test($policy_name, $option_name);
        
        $policy_info = [
            "policy" => $policy_name,
            "current_option" => $option_name,
            "current_option_description" => $ui_options[$policy_name][$option_name] ?? "Unknown option",
            "what_this_tests" => "This policy tests: " . ($ui_options[$policy_name][$option_name] ?? "Unknown option"),
            "complete_configuration" => $complete_configuration,
            "statistics" => $this->policy_stats ?? [
                "total_combinations" => 0,
                "permit_count" => 0,
                "deny_count" => 0
            ]
        ];
        
        $json_path = $policy_dir . "/policy.json";
        @file_put_contents($json_path, json_encode($policy_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    private function get_complete_configuration_for_test($testing_policy, $testing_option) {
        // Show which option is chosen for ALL 9 policy groups for this specific test
        $configuration = [];
        
        foreach ($this->policies as $policy_name => $options) {
            if ($policy_name === $testing_policy) {
                // This is the policy being tested - use the test option
                $configuration[$policy_name] = [
                    "chosen_option" => $testing_option,
                    "description" => $this->get_ui_description($policy_name, $testing_option),
                    "is_being_tested" => true
                ];
            } else {
                // This is a baseline policy - use the baseline option
                $baseline_option = $this->get_baseline_option($policy_name);
                $configuration[$policy_name] = [
                    "chosen_option" => $baseline_option,
                    "description" => $this->get_ui_description($policy_name, $baseline_option),
                    "is_being_tested" => false
                ];
            }
        }
        
        return $configuration;
    }
    
    private function get_baseline_option($policy_name) {
        // Get the baseline option for each policy
        $baseline_options = [
            "author_names_hidden_from_reviewers" => "blind",
            "author_update_submission" => "allow_updates",
            "authors_view_decision" => "no",
            "can_view_review_content" => "after_review",
            "can_view_reviewer_identity" => "always",
            "pc_can_view_incomplete_submission" => "unchecked",
            "pc_can_view_submitted_pdf" => "unchecked",
            "reviewer_view_comments" => "if_reviewers_visible",
            "reviewers_view_decision" => "no"
        ];
        
        return $baseline_options[$policy_name] ?? "unknown";
    }
    
    private function get_ui_description($policy_name, $option) {
        // Get UI description for any policy/option combination
        $ui_options = [
            "author_names_hidden_from_reviewers" => [
                "open" => "Never hide author names from reviewers",
                "optional" => "Hide author names optionally (baseline)",
                "blind" => "Always hide author names from reviewers",
                "blind_until_review" => "Hide author names until review starts"
            ],
            "author_update_submission" => [
                "allow_updates" => "Allow authors to update submissions (baseline)",
                "freeze_submissions" => "Freeze all submissions"
            ],
            "authors_view_decision" => [
                "no" => "Authors cannot see decisions (baseline)",
                "yes" => "Authors can see decisions"
            ],
            "can_view_review_content" => [
                "never" => "Never view reviews",
                "after_review" => "View reviews only after completing review (baseline)",
                "always" => "Always view reviews",
                "assignment_not_incomplete" => "View reviews unless incomplete",
                "all_assignments_complete" => "View reviews after completing all assigned reviews"
            ],
            "can_view_reviewer_identity" => [
                "never" => "Never see reviewer names",
                "after_review" => "See reviewer names only after review",
                "if_assigned" => "See reviewer names only if assigned",
                "always" => "Always see reviewer names (baseline)"
            ],
            "pc_can_view_incomplete_submission" => [
                "unchecked" => "PC cannot view incomplete submissions (baseline)",
                "checked" => "PC can view incomplete submissions"
            ],
            "pc_can_view_submitted_pdf" => [
                "unchecked" => "PC cannot view submitted PDFs (baseline)",
                "checked" => "PC can view submitted PDFs"
            ],
            "reviewer_view_comments" => [
                "if_reviewers_visible" => "View comments only when can see reviewer names (baseline)",
                "yes" => "Always view comments"
            ],
            "reviewers_view_decision" => [
                "no" => "Reviewers cannot see decisions (baseline)",
                "unconflicted" => "Reviewers can see decisions unless they have a conflict",
                "yes" => "Reviewers can see decisions"
            ]
        ];
        
        return $ui_options[$policy_name][$option] ?? "Unknown option";
    }
    
    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Authorization matrix testing script")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new GetAuthorizations($conf->root_user()))->run();
    }
}
