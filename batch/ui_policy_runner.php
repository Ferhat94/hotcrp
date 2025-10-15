<?php
// ui_policy_runner.php -- UI Policy Testing Script
// Replicates exactly what the UI does for policy testing

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(UIPolicyRunner::run_args($argv));
}

class UIPolicyRunner {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting UI Policy Runner...\n");

        // STEP 1: Load all users and papers from the database
        $users = [];
        $rs = $this->conf->qe("SELECT contactId FROM ContactInfo");
        while (($row = $rs->fetch_object())) {
            $user = $this->conf->user_by_id((int) $row->contactId);
            $users[] = $user;
        }
        Dbl::free($rs);

        fwrite(STDOUT, "Loaded " . count($users) . " users\n");

        $papers = [];
        $prs = $this->conf->qe("SELECT * FROM Paper");
        while (($prow = PaperInfo::fetch($prs, null, $this->conf))) {
            $papers[] = $prow;
        }
        Dbl::free($prs);

        fwrite(STDOUT, "Loaded " . count($papers) . " papers\n\n");

        // STEP 2: Define all policy groups with baseline + one-change-at-a-time
        $policy_groups = [
            "submission_visibility" => [
                "baseline" => [
                    "sub_blind" => Conf::BLIND_OPTIONAL,  // Author names: depends
                ],
                "tests" => [
                    "always_hide_authors" => ["sub_blind" => Conf::BLIND_ALWAYS],
                    "never_hide_authors" => ["sub_blind" => Conf::BLIND_NEVER],
                    "hide_until_review" => ["sub_blind" => Conf::BLIND_UNTILREVIEW],
                ]
            ],
            "submission_editing" => [
                "baseline" => [
                    "sub_freeze" => 0,  // Allow updates
                ],
                "tests" => [
                    "freeze_submissions" => ["sub_freeze" => 1],
                ]
            ],
            "decision_visibility" => [
                "baseline" => [
                    "seedec" => 0,  // Reviewers cannot see decisions
                    "decision_visibility_author" => 0,  // Authors cannot see decisions
                ],
                "tests" => [
                    "reviewers_see_decisions" => ["seedec" => Conf::SEEDEC_REV],
                    "reviewers_see_decisions_unless_conflict" => ["seedec" => Conf::SEEDEC_NCREV],
                    "authors_see_decisions" => ["decision_visibility_author" => 2],
                ]
            ],
            "review_content_visibility" => [
                "baseline" => [
                    "viewrev" => Conf::VIEWREV_UNLESSANYINCOMPLETE,  // After completing all assigned reviews
                    "viewrev_ext" => Conf::VIEWREV_UNLESSANYINCOMPLETE,
                ],
                "tests" => [
                    "always_view_reviews" => [
                        "viewrev" => Conf::VIEWREV_ALWAYS,
                        "viewrev_ext" => Conf::VIEWREV_ALWAYS
                    ],
                    "view_reviews_unless_incomplete" => [
                        "viewrev" => Conf::VIEWREV_UNLESSINCOMPLETE,
                        "viewrev_ext" => Conf::VIEWREV_UNLESSINCOMPLETE
                    ],
                    "view_reviews_after_review" => [
                        "viewrev" => Conf::VIEWREV_AFTERREVIEW,
                        "viewrev_ext" => Conf::VIEWREV_AFTERREVIEW
                    ],
                ]
            ],
            "reviewer_identity_visibility" => [
                "baseline" => [
                    "viewrevid" => Conf::VIEWREV_ALWAYS,  // Always see reviewer names
                    "viewrevid_ext" => Conf::VIEWREV_ALWAYS,
                ],
                "tests" => [
                    "reviewer_names_if_assigned" => [
                        "viewrevid" => Conf::VIEWREV_IFASSIGNED,
                        "viewrevid_ext" => Conf::VIEWREV_IFASSIGNED
                    ],
                    "reviewer_names_after_review" => [
                        "viewrevid" => Conf::VIEWREV_AFTERREVIEW,
                        "viewrevid_ext" => Conf::VIEWREV_AFTERREVIEW
                    ],
                    "never_see_reviewer_names" => [
                        "viewrevid" => Conf::VIEWREV_NEVER,
                        "viewrevid_ext" => Conf::VIEWREV_NEVER
                    ],
                ]
            ],
            "pc_submission_access" => [
                "baseline" => [
                    "pc_seeall" => 0,  // PC cannot view incomplete submissions
                    "pc_seeallpdf" => 0,  // PC cannot view submitted PDFs
                ],
                "tests" => [
                    "pc_view_incomplete" => ["pc_seeall" => 1],
                    "pc_view_pdfs" => ["pc_seeallpdf" => 1],
                ]
            ],
            "comment_visibility" => [
                "baseline" => [
                    "cmt_revid" => 0,  // View comments only when can see reviewer names
                ],
                "tests" => [
                    "always_view_comments" => ["cmt_revid" => 1],
                ]
            ],
            "review_self_assignment" => [
                "baseline" => [
                    "pcrev_any" => 1,  // PC can review any paper (baseline)
                ],
                "tests" => [
                    "no_self_assignment" => ["pcrev_any" => 0],
                ]
            ]
        ];

        // STEP 3: Create output directory
        $output_dir = getcwd() . "/logs/ui_policies";
        if (!is_dir($output_dir)) {
            @mkdir($output_dir, 0777, true);
        }

        // STEP 4: Test each policy group
        $total_policies = 0;
        foreach ($policy_groups as $group_name => $group_config) {
            fwrite(STDOUT, "Testing policy group: {$group_name}\n");
            
            // Test baseline first
            $this->test_policy_group($group_name, "baseline", $group_config["baseline"], $users, $papers, $output_dir);
            $total_policies++;
            
            // Test each variation
            foreach ($group_config["tests"] as $test_name => $test_settings) {
                $this->test_policy_group($group_name, $test_name, $test_settings, $users, $papers, $output_dir);
                $total_policies++;
            }
        }

        fwrite(STDOUT, "\nUI Policy Runner complete. Tested {$total_policies} policies.\n");
        return 0;
    }

    private function test_policy_group($group_name, $test_name, $settings, $users, $papers, $output_dir) {
        fwrite(STDOUT, "  Testing: {$group_name}/{$test_name}\n");

        // STEP 1: Set ALL settings to baseline first (like UI does)
        $this->set_all_baseline_settings();
        
        // STEP 2: Apply ONLY the settings for this test (like UI does)
        $this->apply_settings($settings);
        
        // STEP 3: Create output directory for this policy
        $policy_dir = $output_dir . "/" . $group_name . "_" . $test_name;
        if (!is_dir($policy_dir)) {
            @mkdir($policy_dir, 0777, true);
        }
        
        // STEP 4: Write policy information
        $this->write_policy_info($policy_dir, $group_name, $test_name, $settings);
        
        // STEP 5: Test permissions for this policy
        $results = $this->test_permissions($group_name, $users, $papers);
        
        // STEP 6: Write results to CSV
        $this->write_results_csv($policy_dir, $results, $group_name, $test_name);
    }

    private function set_all_baseline_settings() {
        // Set ALL settings to baseline (exactly like UI does)
        $baseline_settings = [
            // Submission visibility
            "sub_blind" => Conf::BLIND_OPTIONAL,
            
            // Submission editing
            "sub_freeze" => 0,
            
            // Decision visibility
            "seedec" => 0,
            "decision_visibility_author" => 0,
            
            // Review content visibility
            "viewrev" => Conf::VIEWREV_UNLESSANYINCOMPLETE,
            "viewrev_ext" => Conf::VIEWREV_UNLESSANYINCOMPLETE,
            
            // Reviewer identity visibility
            "viewrevid" => Conf::VIEWREV_ALWAYS,
            "viewrevid_ext" => Conf::VIEWREV_ALWAYS,
            
            // PC submission access
            "pc_seeall" => 0,
            "pc_seeallpdf" => 0,
            
            // Comment visibility
            "cmt_revid" => 0,
            
            // Review self assignment
            "pcrev_any" => 1,
        ];
        
        // Apply all baseline settings (like UI does)
        foreach ($baseline_settings as $name => $value) {
            $this->conf->save_setting($name, $value);
        }
        
        // Refresh settings (like UI does)
        $this->conf->refresh_settings();
        Contact::update_rights();
    }

    private function apply_settings($settings) {
        // Apply specific settings for this test (like UI does)
        foreach ($settings as $name => $value) {
            $this->conf->save_setting($name, $value);
        }
        
        // Refresh settings (like UI does)
        $this->conf->refresh_settings();
        Contact::update_rights();
    }

    private function test_permissions($group_name, $users, $papers) {
        $results = [];
        
        foreach ($users as $user) {
            foreach ($papers as $paper) {
                // Test the appropriate permission based on the policy group
                $permission_result = $this->test_group_permission($user, $paper, $group_name);
                
                $results[] = [
                    "user" => "u" . $user->contactId,
                    "resource" => "p" . $paper->paperId,
                    "decision" => $permission_result ? "Permit" : "Deny"
                ];
            }
        }
        
        return $results;
    }

    private function test_group_permission($user, $paper, $group_name) {
        // Test the appropriate permission based on the policy group
        switch ($group_name) {
            case "submission_visibility":
                return $user->can_view_authors($paper);
                
            case "submission_editing":
                return $user->can_edit_paper($paper);
                
            case "decision_visibility":
                return $user->can_view_decision($paper);
                
            case "review_content_visibility":
                return $user->can_view_review($paper, null);
                
            case "reviewer_identity_visibility":
                return $user->can_view_review_identity($paper);
                
            case "pc_submission_access":
                return $user->can_view_paper($paper, false); // incomplete submissions
                
            case "comment_visibility":
                // Create dummy comment for testing
                $comment = new CommentInfo();
                $comment->conf = $this->conf;
                $comment->prow = $paper;
                $comment->paperId = $paper->paperId;
                $comment->commentId = 1;
                $comment->contactId = 999;
                $comment->commentType = CommentInfo::CTVIS_REVIEWER | CommentInfo::CT_TOPIC_PAPER;
                return $user->can_view_comment($paper, $comment);
                
            case "review_self_assignment":
                // Test if user can self-assign reviews
                return $user->can_self_assign_review($paper);
                
            default:
                return false;
        }
    }

    private function write_policy_info($policy_dir, $group_name, $test_name, $settings) {
        $policy_info = [
            "group" => $group_name,
            "test" => $test_name,
            "settings_changed" => $settings,
            "description" => $this->get_policy_description($group_name, $test_name),
            "timestamp" => date("Y-m-d H:i:s")
        ];
        
        $json_path = $policy_dir . "/policy_info.json";
        @file_put_contents($json_path, json_encode($policy_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function get_policy_description($group_name, $test_name) {
        $descriptions = [
            "submission_visibility" => [
                "baseline" => "Author names: depends (baseline)",
                "always_hide_authors" => "Always hide author names from reviewers",
                "never_hide_authors" => "Never hide author names from reviewers",
                "hide_until_review" => "Hide author names until review starts"
            ],
            "submission_editing" => [
                "baseline" => "Allow authors to update submissions (baseline)",
                "freeze_submissions" => "Freeze all submissions"
            ],
            "decision_visibility" => [
                "baseline" => "Reviewers and authors cannot see decisions (baseline)",
                "reviewers_see_decisions" => "Reviewers can see decisions",
                "reviewers_see_decisions_unless_conflict" => "Reviewers can see decisions unless they have a conflict",
                "authors_see_decisions" => "Authors can see decisions"
            ],
            "review_content_visibility" => [
                "baseline" => "View reviews after completing all assigned reviews (baseline)",
                "always_view_reviews" => "Always view review content",
                "view_reviews_unless_incomplete" => "View reviews unless incomplete",
                "view_reviews_after_review" => "View reviews only after completing review"
            ],
            "reviewer_identity_visibility" => [
                "baseline" => "Always see reviewer names (baseline)",
                "reviewer_names_if_assigned" => "See reviewer names only if assigned",
                "reviewer_names_after_review" => "See reviewer names only after review",
                "never_see_reviewer_names" => "Never see reviewer names"
            ],
            "pc_submission_access" => [
                "baseline" => "PC cannot view incomplete submissions or PDFs (baseline)",
                "pc_view_incomplete" => "PC can view incomplete submissions",
                "pc_view_pdfs" => "PC can view submitted PDFs"
            ],
            "comment_visibility" => [
                "baseline" => "View comments only when can see reviewer names (baseline)",
                "always_view_comments" => "Always view comments"
            ],
            "review_self_assignment" => [
                "baseline" => "PC can review any paper (baseline)",
                "no_self_assignment" => "PC cannot self-assign reviews"
            ]
        ];
        
        return $descriptions[$group_name][$test_name] ?? "Unknown policy";
    }

    private function write_results_csv($policy_dir, $results, $group_name, $test_name) {
        $csvname = $group_name . "_" . $test_name . ".csv";
        $filepath = $policy_dir . "/" . $csvname;
        
        $fh = fopen($filepath, "w");
        fputcsv($fh, ["User", "Resource", "Decision"], ",");
        foreach ($results as $row) {
            fputcsv($fh, [$row["user"], $row["resource"], $row["decision"]], ",");
        }
        fclose($fh);
        
        fwrite(STDOUT, "    Wrote: $filepath\n");
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("UI Policy testing script")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new UIPolicyRunner($conf->root_user()))->run();
    }
}
