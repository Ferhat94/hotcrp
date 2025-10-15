<?php
// simple_policy_runner.php -- Simple policy testing script

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(SimplePolicyRunner::run_args($argv));
}

class SimplePolicyRunner {
    /** @var Conf */
    private $conf;
    private $policies;

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
                // From settinginfo.json: " it is differnt" -> "val.au_seedec"
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
                "always" => 1                      // BASELINE (skip) - always see reviewer names
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

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting simple policy runner...\n");

        // STEP 1: Load all users and papers from the database
        $users = [];
        $rs = $this->conf->qe("SELECT contactId FROM ContactInfo");
        while (($row = $rs->fetch_object())) {
            $user = $this->conf->user_by_id((int) $row->contactId);
            $users[] = $user;
        }
        Dbl::free($rs);

        // Print user information
        fwrite(STDOUT, "Loaded " . count($users) . " users:\n");
        foreach ($users as $user) {
            fwrite(STDOUT, "  User {$user->contactId}: {$user->email} ({$user->firstName} {$user->lastName})\n");
        }

        $papers = [];
        $prs = $this->conf->qe("SELECT * FROM Paper");
        while (($prow = PaperInfo::fetch($prs, null, $this->conf))) {
            $papers[] = $prow;
        }
        Dbl::free($prs);

        // Print paper information
        fwrite(STDOUT, "\nLoaded " . count($papers) . " papers:\n");
        foreach ($papers as $paper) {
            fwrite(STDOUT, "  Paper {$paper->paperId}: '{$paper->title}'\n");
        }
        fwrite(STDOUT, "\n");

        // STEP 2: Use policies defined in constructor

        // STEP 3: Create output directory for all policy logs
        $output_dir = getcwd() . "/logs/simple_policies";
        if (!is_dir($output_dir)) {
            @mkdir($output_dir, 0777, true);
        }

        // STEP 4: Test each policy
        foreach ($this->policies as $setting => $options) {
            foreach ($options as $option => $value) {
                // Skip baseline options - only test the non-baseline ones
                if ($this->is_baseline_option($setting, $option)) {
                    continue;
                }
                // Test this policy and generate CSV
                $this->test_policy($setting, $option, $value, $users, $papers, $output_dir);
            }
        }

        fwrite(STDOUT, "Simple policy runner complete.\n");
        return 0;
    }

    private function is_baseline_option($setting, $option) {
        // Define which options are baseline (skip these for now)
        // Based on settinginfo.json default_value and initial_value
        $baseline_options = [
            "author_names_hidden_from_reviewers" => "blind",           // default_value: 2 = "blind"
            "author_update_submission" => "allow_updates",             // default_value: 0 = allow_updates
            "authors_view_decision" => "no",                          // default_value: 0 = "no"
            "can_view_review_content" => "after_review",              // default_value: 0 = "after_review"
            "can_view_reviewer_identity" => "always",                 // initial_value: 1 = "always"
            "pc_can_view_incomplete_submission" => "unchecked",       // checkbox default: 0 = unchecked
            "pc_can_view_submitted_pdf" => "unchecked",               // checkbox default: 0 = unchecked
            "reviewer_view_comments" => "if_reviewers_visible",       // default_value: 0 = "if_reviewers_visible"
            "reviewers_view_decision" => "no"                         // default_value: 0 = "no"
        ];
        
        return isset($baseline_options[$setting]) && $baseline_options[$setting] === $option;
    }

    private function test_policy($setting, $option, $value, $users, $papers, $output_dir) {
        // STEP 1: Set ALL settings to baseline first (Baseline + One-Change-At-a-Time)
        $this->set_baseline_settings();
        
        // STEP 2: Change ONLY the one setting we're testing
        $this->set_setting($setting, $value);
        // DEBUG focus: users 8 and 16 on paper 9 this part is just for debug we should remove later on 
        foreach ($users as $u) {
            foreach ($papers as $p) {
                if (($u->contactId === 8 || $u->contactId === 16) && $p->paperId === 9) {
                    $permit = $this->test_permission($u, $p, $setting) ? 'Permit' : 'Deny';
                    fwrite(STDOUT, "DEBUG: U {$u->contactId} ({$u->email}) P {$p->paperId} setting {$setting} => {$permit}\n");
                    fwrite(STDOUT, "DEBUG: Paper outcome {$p->outcome} outcome_sign {$p->outcome_sign}\n");
                }
            }
        }
        
        // STEP 2.5: Prepare per-policy output directory and snapshot settings
        $policy_dir = $output_dir . "/" . $setting . "_" . $option;
        if (!is_dir($policy_dir)) {
            @mkdir($policy_dir, 0777, true);
        }
        $this->write_settings_snapshot($policy_dir, $setting, $option);
        
        // STEP 3: Test every user against every paper using HotCRP's built-in functions
        // We call the actual HotCRP permission functions from contact.php
        $results = [];
        foreach ($users as $user) {
            foreach ($papers as $paper) {
                // Call the appropriate HotCRP function (can_view_decision, can_view_authors, etc.)
                $decision = $this->test_permission($user, $paper, $setting);
                $results[] = [
                    "user" => "u" . $user->contactId,
                    "resource" => "p" . $paper->paperId,
                    "decision" => $decision ? "Permit" : "Deny"  // Convert true/false to Permit/Deny
                ];
            }
        }

        // STEP 4: Write results to CSV file inside per-policy directory
        $csvname = $setting . "_" . $option . ".csv";
        $filepath = $policy_dir . "/" . $csvname;
        
        $fh = fopen($filepath, "w");
        fputcsv($fh, ["User", "Resource", "Decision"], ",");
        foreach ($results as $row) {
            fputcsv($fh, [$row["user"], $row["resource"], $row["decision"]], ",");
        }
        fclose($fh);

        fwrite(STDOUT, "Wrote: $filepath\n");
    }

    private function write_settings_snapshot($policy_dir, $setting, $option) {
        // Map policies to UI options for clarity
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
        $complete_configuration = $this->get_complete_configuration_for_test($setting, $option);
        
        $snapshot = [
            "policy" => $setting,
            "current_option" => $option,
            "current_option_description" => $ui_options[$setting][$option] ?? "Unknown option",
            "what_this_tests" => "This policy tests: " . ($ui_options[$setting][$option] ?? "Unknown option"),
            "complete_configuration" => $complete_configuration
        ];
        $json_path = $policy_dir . "/policy_info.json";
        @file_put_contents($json_path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

    private function get_current_settings_for_policy($setting, $option) {
        // Return the specific settings that are being used for this policy test
        $settings_info = [];
        
        switch ($setting) {
            case "author_names_hidden_from_reviewers":
                $settings_info["sub_blind"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls whether author names are hidden from reviewers";
                break;
                
            case "author_update_submission":
                $settings_info["sub_freeze"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls whether authors can update their submissions";
                break;
                
            case "authors_view_decision":
                $settings_info["au_seedec"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls whether authors can view decisions";
                break;
                
            case "can_view_review_content":
                $settings_info["viewrev"] = $this->policies[$setting][$option];
                $settings_info["viewrev_ext"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls who can view review content (PC and external reviewers)";
                break;
                
            case "can_view_reviewer_identity":
                $settings_info["viewrevid"] = $this->policies[$setting][$option];
                $settings_info["viewrevid_ext"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls who can see reviewer identities (PC and external reviewers)";
                break;
                
            case "pc_can_view_incomplete_submission":
                $settings_info["pc_seeall"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls whether PC can view incomplete submissions";
                break;
                
            case "pc_can_view_submitted_pdf":
                $settings_info["pc_seeallpdf"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls whether PC can view submitted PDFs";
                break;
                
            case "reviewer_view_comments":
                $settings_info["cmt_revid"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls whether reviewers can view comments";
                break;
                
            case "reviewers_view_decision":
                $settings_info["seedec"] = $this->policies[$setting][$option];
                $settings_info["description"] = "Controls whether reviewers can view decisions";
                break;
        }
        
        return $settings_info;
    }

    private function set_baseline_settings() {
        // Define the baseline settings for all policies (Baseline + One-Change-At-a-Time)
        // Use save_setting() to replicate exactly what the UI does
        $baseline = [
            "sub_blind" => 2,                              // Author names: blind (default_value: 2)
            "sub_freeze" => 0,                             // Author updates: allow_updates (default_value: 0)
            "seedec" => 0,                                 // Reviewers cannot see decisions (default_value: 0)
            "au_seedec" => 0,                              // Authors cannot see decisions (default_value: 0)
            "viewrev" => 0,                                // View review content: after_review (default_value: 0)
            "viewrev_ext" => 0,                            // External reviewers: after_review (default_value: 0)
            "viewrevid" => 1,                              // View reviewer identity: always (initial_value: 1)
            "viewrevid_ext" => 0,                          // External reviewers: after_review (default_value: 0)
            "pc_seeall" => 0,                              // PC view incomplete: unchecked (checkbox default: 0)
            "pc_seeallpdf" => 0,                           // PC view PDF: unchecked (checkbox default: 0)
            "cmt_revid" => 0                               // Reviewer view comments: if_reviewers_visible (default_value: 0)
        ];
        
        // Save all baseline settings to database (like UI does)
        foreach ($baseline as $name => $val) {
            $this->conf->save_setting($name, $val);
        }
        
        // Refresh HotCRP's internal state to apply the baseline settings
        $this->conf->refresh_settings();
        Contact::update_rights();
    }

    private function set_setting($setting, $value) {
        // This function saves settings to database (like UI does)
        // Each setting maps to a specific HotCRP configuration option
        
        switch ($setting) {
            case "author_names_hidden_from_reviewers":
                // Controls whether author names are hidden from reviewers
                $this->conf->save_setting("sub_blind", $value);
                break;
                
            case "author_update_submission":
                // Controls whether authors can update their submissions
                $this->conf->save_setting("sub_freeze", $value);
                break;
                
            case "authors_view_decision":
                // Controls whether authors can view decisions
                // Use author-side backend knob consumed by Conf::refresh_settings
                // 0 => No, 2 => Yes (all authors), 1 => conditional (requires settingTexts["au_seedec"]) 
                $this->conf->save_setting("au_seedec", $value);
                break;
                
            case "can_view_review_content":
                // Controls who can view review content
                $this->conf->save_setting("viewrev", $value);
                $this->conf->save_setting("viewrev_ext", $value);
                break;
                
            case "can_view_reviewer_identity":
                // Controls who can see reviewer identities
                $this->conf->save_setting("viewrevid", $value);
                $this->conf->save_setting("viewrevid_ext", $value);
                break;
                
            case "pc_can_view_incomplete_submission":
                // Controls whether PC can view incomplete submissions
                $this->conf->save_setting("pc_seeall", $value);
                break;
                
            case "pc_can_view_submitted_pdf":
                // Controls whether PC can view submitted PDFs
                $this->conf->save_setting("pc_seeallpdf", $value);
                break;
                
            case "reviewer_view_comments":
                // Controls whether reviewers can view comments
                $this->conf->save_setting("cmt_revid", $value);
                break;
                
            case "reviewers_view_decision":
                // Controls whether reviewers can view decisions
                $this->conf->save_setting("seedec", $value);
                break;
        }
        
        // Refresh HotCRP's internal state to apply the new settings
        $this->conf->refresh_settings();
        Contact::update_rights();
    }

    private function test_permission($user, $paper, $setting) {
        // This function calls the actual HotCRP permission functions from contact.php
        // Each function reads the current Conf::$settings and applies the policy logic
        
        switch ($setting) {
            case "author_names_hidden_from_reviewers":
                // Tests if user can see author names (reads sub_blind setting)
                return $user->can_view_authors($paper);
                
            case "author_update_submission":
                // Tests if user can edit/update paper (reads sub_edit setting)
                return $user->can_edit_paper($paper);
                
            case "authors_view_decision":
                // Tests if user can view decision (reads seedec setting)
                // This is the SAME function as reviewers_view_decision!
                return $user->can_view_decision($paper);
                
            case "can_view_review_content":
                // Tests if user can view review content (reads viewrev setting)
                return $user->can_view_review($paper, null);
                
            case "can_view_reviewer_identity":
                // Tests if user can see reviewer names (reads viewrevid setting)
                return $user->can_view_review_identity($paper);
                
            case "pc_can_view_incomplete_submission":
                // Tests if PC can view incomplete papers (reads pc_seeall setting)
                return $user->can_view_paper($paper, false);
                
            case "pc_can_view_submitted_pdf":
                // Tests if PC can view submitted PDFs (reads pc_seeallpdf setting)
                return $user->can_view_paper($paper, true);
                
            case "reviewer_view_comments":
                // Tests if user can view reviewer comments (reads cmt_revid setting)
                // Create dummy comment for testing
                $comment = new CommentInfo();
                $comment->conf = $this->conf;
                $comment->prow = $paper;
                $comment->paperId = $paper->paperId;
                $comment->commentId = 1;
                $comment->contactId = 999;
                $comment->commentType = CommentInfo::CTVIS_REVIEWER | CommentInfo::CT_TOPIC_PAPER;
                return $user->can_view_comment($paper, $comment);
                
            case "reviewers_view_decision":
                // Tests if user can view decision (reads seedec setting)
                // This is the SAME function as authors_view_decision!
                return $user->can_view_decision($paper);
                
            default:
                return false;
        }
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Simple policy testing script")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new SimplePolicyRunner($conf->root_user()))->run();
    }
}
