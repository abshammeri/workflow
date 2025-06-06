<?php

namespace Saudinic\Workflow\Tests\Fixtures;

use Saudinic\Workflow\BaseWorkflowDefinition;
use Saudinic\Workflow\Tests\Fixtures\BlogPost; // Ensure this namespace is correct

class BlogPostWorkflow extends BaseWorkflowDefinition
{
    public function configure(): void
    {
        $this->setName('blog_post_workflow')
             ->setModelClass(BlogPost::class)
             ->setStateColumn('current_status') // Matches the property in BlogPost
             ->setInitialPlace('draft')
             ->addPlace('draft', ['title' => 'Draft Post', 'description' => 'Initial state of the post.'])
             ->addPlace('reviewed', ['title' => 'Reviewed Post', 'description' => 'Post has been reviewed.'])
             ->addPlace('published', ['title' => 'Published Post', 'description' => 'Post is live.'])
             ->addTransition('submit_review', 'draft', 'reviewed')
             ->addTransition('publish_post', 'reviewed', 'published')

             // Add Task Definitions
             ->addTaskDefinition('proofread_post', [
                 'name' => 'Proofread Blog Post',
                 'on_enter_state' => 'reviewed',
                 'type' => 'manual', // Manual task
                 'assignee_type' => 'role',
                 'assignee_value' => 'editor',
                 'metadata' => ['instructions' => 'Check for grammar and clarity.'],
             ])
             ->addTaskDefinition('notify_author_published', [
                 'name' => 'Notify Author of Publication',
                 'on_enter_state' => 'published',
                 'type' => 'automatic', // Automatic task
                 'handler' => 'handleNotifyAuthor', // Assumes BlogPost model has a 'handleNotifyAuthor' method
                 'metadata' => ['channel' => 'email'],
             ])

             // Add SLA Definition
             ->addSlaDefinition('review_time_sla', [
                 'name' => 'Time in Review SLA',
                 'applies_to_state' => 'reviewed', // SLA starts when post enters 'reviewed' state
                 'duration_seconds' => 60 * 60 * 24, // 1 day
                 'on_breach_handler' => 'handleReviewSlaBreach', // Assumes method on BlogPost model
                 'metadata' => ['severity' => 'high', 'escalation_policy' => 'policy_xyz'],
             ]);
    }
}
