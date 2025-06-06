<?php

namespace Saudinic\Workflow\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Saudinic\Workflow\HasWorkflow;

// If not using a full Laravel test environment, this might need to be a plain class
// or a more sophisticated mock. For now, assume Eloquent Model is available or its absence handled.
class BlogPost extends Model
{
    use HasWorkflow;

    // Matches the 'setStateColumn' in BlogPostWorkflow
    public $current_status;
    public $title;

    // Required by Eloquent Model, but can be simplified if not using real DB for tests.
    protected $fillable = ['title', 'current_status'];
    protected $table = 'blog_posts'; // Mock table name

    // Mock Eloquent model behavior if not using a full Laravel test environment
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        // Initialize current_status from attributes or default if necessary
        $this->current_status = $attributes['current_status'] ?? null;
    }

    // Mock save if not relying on actual DB interaction
    public function save(array $options = []): bool
    {
        // In a real scenario, this would persist to DB.
        // For tests, we might just simulate it or check if it's called.
        // echo "BlogPost saved with status: {$this->current_status}\n";
        return true;
    }

    // Mocked methods for Symfony Workflow integration if not a full Eloquent model
    public function getKey()
    {
        return $this->id ?? 1; // Mock primary key
    }

    public function getTable(): string
    {
        return 'blog_posts';
    }

    // Example of a custom getter that might be used if state column was 'status'
    // public function getStatus() { return $this->current_status; }
    // public function setStatus($value) { $this->current_status = $value; }

    /**
     * Mock handler for the 'notify_author_published' automatic task.
     * In a real application, this would send an email or notification.
     */
    public function handleNotifyAuthor(array $taskData): array
    {
        // $this refers to the BlogPost instance
        // $taskData contains the data passed to _createWorkflowTaskRecord
        $this->author_notified = true; // Mark that author was notified (for testing)
        // echo "Author of post ID {$this->getKey()} notified about publication. Task Data: " . json_encode($taskData) . "\n";
        return ['notification_sent' => true, 'channel' => $taskConfig['metadata']['channel'] ?? 'unknown']; // Corrected to use $taskConfig
    }
    public $author_notified = false; // Test property

    /**
     * Mock handler for the 'review_time_sla' breach.
     * In a real application, this might escalate the issue, send notifications, etc.
     * @param array $slaBreachData Data about the SLA breach.
     */
    public function handleReviewSlaBreach(array $slaBreachData): void
    {
        // $this refers to the BlogPost instance
        // $slaBreachData would typically contain info about the SLA key, due time, breach time, etc.
        $this->reviewSlaBreached = true; // Mark that SLA was breached (for testing)
        // echo "Review SLA breached for post ID {$this->getKey()}. Breach Data: " . json_encode($slaBreachData) . "\n";
    }
    public $reviewSlaBreached = false; // Test property
}
