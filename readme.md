# My Workflow

This package is a wrapper around symfony workflow.

# Installation

```bash

{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:abshammeri/workflow.git"
        }
    ],
    "require": {
        "abshammeri/workflow": "^1.0",
    }

}

composer install

```

# Basic Usage

First you need to create a plain php object.

```php
use Abshammeri\Workflow\HasWorkflow;

class Blog
{
    use HasWorkflow;
    public $title;
    public $updatedAt;

    // the default name is state.
    public $step;

    // the default name is state.
    public function getStateName(){
        return 'step';
    }
}

//

try {

            $blog = new Blog();

            $config = [
                'name' => 'blog_post',
                'initial_place' => 'draft',
                'places' =>
                    [
                        'draft',
                        'under_review',
                        'published',
                        'rejected',
                    ],
                'transitions' =>
                    [
                        //
                        'to_review' => ['from' => 'draft',
                                     'to' => 'under_review',
                        ],

                        //
                        'return_back' => ['from' => 'under_review',
                                     'to' => 'draft',
                        ],
                        //
                        'publish' => ['from' => 'under_review',
                                     'to' => 'published',
                        ],

                        //
                        'reject' => ['from' => 'under_review',
                                     'to' => 'rejected',
                        ],

                    ],

            ];


            $blog->activateWorkflow($config);

            $blog->canOrFail('to_review');

            echo $blog->step . PHP_EOL;

            $blog->applyOrFail('to_review');

            echo $blog->step . PHP_EOL;

            $blog->applyOrFail('publish');

            echo $blog->step . PHP_EOL;


            echo $blog->hasEnded() . PHP_EOL;


        }catch(WorkflowException $e){
            echo $e->getMessage();
        }


```


# Laravel Models

You can use it with laravel model just like any other objects.


```php
        Schema::create('tickets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('state');
            $table->string('subject');
        });
```


```php


use Illuminate\Database\Eloquent\Model;
use Saudinic\Workflow\HasWorkflow;


class Ticket extends Model
{
    use HasWorkflow;

    // default state name is `state` you can change this if you wnat
    // public function getStateName(){
    //   return "step";
    //} 

}

// usage

        Artisan::call('migrate:fresh');

        try {
            $ticket = new Ticket();

            $ticket->activateWorkflow(config('ticket'));

            $ticket->applyOrFail('create');
            $ticket->applyOrFail('answer');
            $ticket->applyOrFail('escalate');

            $ticket->subject = "Title";
            
            // you can persist the state if you like.
            $ticket->save();

            $newState = Ticket::where('state', $ticket->state)->latest('id')->firstOrFail()->state;
            
            echo $newState;

        }catch(WorkflowException $e){
            echo $e->getMessage();
        }


```
