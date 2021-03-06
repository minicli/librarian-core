<?php

namespace Librarian\Provider;

use Librarian\Content;
use Librarian\ContentCollection;
use Librarian\Exception\ContentNotFoundException;
use Minicli\App;
use Minicli\ServiceInterface;
use Minicli\Minicache\FileCache;
use Librarian\Request;
use Parsed\ContentParser;
use Parsed\CustomTagParserInterface;

class ContentServiceProvider implements ServiceInterface
{
    /** @var string */
    protected $data_path;

    /** @var string */
    protected $cache_path;

    /** @var array */
    protected $parser_params = [];

    /** @var ContentParser */
    protected $parser;

    /**
     * @param App $app
     * @throws \Exception
     */
    public function load(App $app)
    {
        if (!$app->config->has('data_path')) {
            throw new \Exception("Missing Data Path.");
        }

        if (!$app->config->has('cache_path')) {
            throw new \Exception("Missing Cache Path.");
        }

        $this->data_path = $app->config->data_path;
        $this->cache_path = $app->config->cache_path;

        if ($app->config->has('parser_params')) {
            $this->parser_params = $app->config->parser_params;
        }

        $this->parser = new ContentParser($this->parser_params);
    }

    public function registerTagParser(string $name, CustomTagParserInterface $tag_parser)
    {
        $this->parser->addCustomTagParser($name, $tag_parser);
    }

    /**
     * @param string $route
     * @param bool $parse_markdown
     * @return Content
     */
    public function fetch(string $route, $parse_markdown = true)
    {
        $request = new Request([], '/' . $route);
        $filename = $this->data_path . '/' . $request->getRoute() . '/' . $request->getSlug() . '.md';
        $content = new Content();

        try {
            $content->load($filename);
            $content->setRoute($request->getRoute());

            $content->parse($this->parser, $parse_markdown);
        } catch (ContentNotFoundException $e) {
            return null;
        }

        return $content;
    }

    /**
     * @param int $start
     * @param int $limit
     * @param bool $parse_markdown
     * @param string $orderBy
     * @return ContentCollection
     */
    public function fetchAll(int $start = 0, int $limit = 20, bool $parse_markdown = false, $orderBy = 'desc'): ContentCollection
    {
        $list = [];
        foreach (glob($this->data_path . '/*') as $route) {
            $content_type = basename($route);
            foreach (glob($route . '/*.md') as $filename) {
                $content = new Content();
                try {
                    $content->load($filename);
                    $content->parse($this->parser, $parse_markdown);
                    $content->setRoute($content_type);
                    $list[] = $content;
                } catch (ContentNotFoundException $e) {
                    continue;
                } catch (\Exception $e) {
                }
            }
        }

        $list = $this->orderBy($list, $orderBy);
        $collection = new ContentCollection($list);

        if ($limit === 0) {
            return $collection;
        }

        return $collection->slice($start, $limit);
    }

    /**
     * @param int $per_page
     * @return int|mixed
     */
    public function fetchTotalPages($per_page = 20)
    {
        $cache = new FileCache($this->cache_path);
        $cache_id = "full_pagination";

        $cached_content = $cache->getCachedUnlessExpired($cache_id);

        if ($cached_content !== null) {
            return json_decode($cached_content, true);
        }

        $content = $this->fetchAll(0, 0);

        return (int) ceil($content->total() / $per_page);
    }

    /**
     * @param $tag
     * @param int $per_page
     * @return int
     * @throws \Exception
     */
    public function fetchTagTotalPages($tag, $per_page = 20)
    {
        $collection = $this->fetchFromTag($tag);

        return (int) ceil($collection->total() / $per_page);
    }

    /**
     * @return array|mixed
     */
    public function fetchTagList(bool $cached = true)
    {
        if ($cached) {
            $cache = new FileCache($this->cache_path);
            $cache_id = "full_tag_list";

            $cached_content = $cache->getCachedUnlessExpired($cache_id);

            if ($cached_content !== null) {
                return json_decode($cached_content, true);
            }
        }

        $content = $this->fetchAll(0, 0);
        $tags = [];

        /** @var Content $article */
        foreach ($content as $article) {
            if ($article->frontMatterHas('tags')) {
                $article_tags = explode(',', $article->frontMatterGet('tags'));

                foreach ($article_tags as $article_tag) {
                    $tag_name = trim(str_replace('#', '', $article_tag));

                    $tags[$tag_name][] = $article->getLink();
                }
            }
        }

        if ($cached) {
            $cache->save(json_encode($tags), $cache_id);
        }

        return $tags;
    }

    /**
     * @param $tag
     * @param int $start
     * @param int $limit
     * @return mixed|null
     * @throws \Exception
     */
    public function fetchFromTag($tag, int $start = 0, int $limit = 20)
    {
        $full_tag_list = $this->fetchTagList();
        $collection = new ContentCollection();
        if (key_exists($tag, $full_tag_list)) {
            foreach ($full_tag_list[$tag] as $route) {
                $article = $this->fetch($route);
                $collection->add($article);
            }

            if (!$limit) {
                return $collection;
            }

            return $collection->slice($start, $limit);
        }

        return null;
    }

    /**
     * @return array
     */
    public function getContentTypes(): array
    {
        $content_types = [];
        foreach (glob($this->data_path . '/*') as $route) {
            $content_types[] = basename($route);
        }

        return $content_types;
    }

    /**
     * @param $route
     * @param int $start
     * @param int $limit
     * @param bool $parse_markdown
     * @param string $orderBy
     * @return ContentCollection
     */
    public function fetchFrom($route, int $start = 0, int $limit = 20, bool $parse_markdown = false, $orderBy = 'desc')
    {
        $feed = [];

        foreach (glob($this->data_path . '/' . $route . '/*.md') as $filename) {
            $content = new Content();
            try {
                $content->load($filename);
                $content->parse($this->parser, $parse_markdown);
                $content->setRoute($route);
                $feed[] = $content;
            } catch (ContentNotFoundException $e) {
                continue;
            } catch (\Exception $e) {
            }
        }

        $feed = $this->orderBy($feed, $orderBy);
        $collection = new ContentCollection($feed);

        if ($limit === 0) {
            return $collection;
        }

        return $collection->slice($start, $limit);
    }

    public function orderBy(array $content, $orderBy = 'desc')
    {
        uasort($content, function (Content $content1, Content $content2) {
            return (strtolower($content1->slug) < strtolower($content2->slug)) ? -1 : 1;
        });

        if ($orderBy === 'desc') {
            $content = array_reverse($content);
        }

        if ($orderBy === 'rand') {
            shuffle($content);
        }

        return $content;
    }
}
