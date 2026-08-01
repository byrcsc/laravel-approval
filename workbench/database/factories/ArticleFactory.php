<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Creating an article submits it: the model implements `AutoSubmitsForApproval`,
 * so anything built through this factory needs the `article-review` workflow to
 * exist and needs an author to attribute the request to.
 *
 * @extends Factory<Article>
 */
final class ArticleFactory extends Factory
{
    /** @var class-string<Article> */
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::headline($this->faker->unique()->sentence(4));

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'body' => $this->faker->paragraphs(3, true),
            'published' => false,
            'author_id' => User::query()->value('id') ?? User::factory(),
        ];
    }
}
