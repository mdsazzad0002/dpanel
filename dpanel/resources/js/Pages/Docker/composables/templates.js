import { i18n } from '@/i18n';
import { freePort, randomSecret } from './useDockerRequest';

const t = (...args) => i18n.global.t(...args);

/*
 * Ready-made apps. Placeholders are filled in when a template is opened:
 *   {{secret}}         a new random password
 *   {{secret:NAME}}    the same random password everywhere NAME appears
 *   {{port:8080}}      8080, or the next server port nothing publishes yet
 * Every port listens on 127.0.0.1 only: apps are reached from the server,
 * from other containers, or through a website on a domain.
 */

export const categories = [
    'all', 'database', 'search', 'cms', 'tools', 'storage',
].map((key) => ({ key, get label() { return t(`docker.templates.categories.${key}`); } }));

// Notes and descriptions are getters, so they follow the panel language;
// instantiate() reads them once and fills the placeholders.
const code = (text) => `<code>${text}</code>`;
const connect = (what) => `<strong>${t('docker.catalog.connect')}</strong> ${what}`;

export const containerTemplates = [
    {
        key: 'redis', name: 'Redis', category: 'database', icon: 'bi bi-lightning-charge', color: 'text-red-600',
        get description() { return t('docker.catalog.redis.description'); },
        spec: {
            image: 'redis:7-alpine', name: 'redis',
            ports: [{ host: '{{port:6380}}', container: 6379 }],
            volumes: [{ source: 'redis-data', target: '/data' }],
            command: ['redis-server', '--appendonly', 'yes', '--requirepass', '{{secret:REDIS}}'],
            memory: '256m',
        },
        get note() { return connect(t('docker.catalog.redis.note', { host: code('127.0.0.1'), port: code('{{port:6380}}'), password: code('{{secret:REDIS}}') })); },
    },
    {
        key: 'postgres', name: 'PostgreSQL', category: 'database', icon: 'bi bi-database', color: 'text-sky-700',
        get description() { return t('docker.catalog.postgres.description'); },
        spec: {
            image: 'postgres:16-alpine', name: 'postgres',
            ports: [{ host: '{{port:5433}}', container: 5432 }],
            env: [{ key: 'POSTGRES_USER', value: 'app' }, { key: 'POSTGRES_PASSWORD', value: '{{secret:PG}}' }, { key: 'POSTGRES_DB', value: 'app' }],
            volumes: [{ source: 'postgres-data', target: '/var/lib/postgresql/data' }],
        },
        get note() { return connect(t('docker.catalog.postgres.note', { host: code('127.0.0.1'), port: code('{{port:5433}}'), app: code('app'), password: code('{{secret:PG}}') })); },
    },
    {
        key: 'mysql', name: 'MySQL 8.4', category: 'database', icon: 'bi bi-database', color: 'text-orange-600',
        get description() { return t('docker.catalog.mysql.description'); },
        spec: {
            image: 'mysql:8.4', name: 'mysql84',
            ports: [{ host: '{{port:3307}}', container: 3306 }],
            env: [
                { key: 'MYSQL_ROOT_PASSWORD', value: '{{secret}}' }, { key: 'MYSQL_DATABASE', value: 'app' },
                { key: 'MYSQL_USER', value: 'app' }, { key: 'MYSQL_PASSWORD', value: '{{secret:MYSQL}}' },
            ],
            volumes: [{ source: 'mysql84-data', target: '/var/lib/mysql' }],
        },
        get note() { return connect(t('docker.catalog.hostPortUserPassword', { host: code('127.0.0.1'), port: code('{{port:3307}}'), user: code('app'), password: code('{{secret:MYSQL}}') })); },
    },
    {
        key: 'mariadb', name: 'MariaDB 11', category: 'database', icon: 'bi bi-database', color: 'text-amber-700',
        get description() { return t('docker.catalog.mariadb.description'); },
        spec: {
            image: 'mariadb:11', name: 'mariadb',
            ports: [{ host: '{{port:3308}}', container: 3306 }],
            env: [
                { key: 'MARIADB_ROOT_PASSWORD', value: '{{secret}}' }, { key: 'MARIADB_DATABASE', value: 'app' },
                { key: 'MARIADB_USER', value: 'app' }, { key: 'MARIADB_PASSWORD', value: '{{secret:MARIA}}' },
            ],
            volumes: [{ source: 'mariadb-data', target: '/var/lib/mysql' }],
        },
        get note() { return connect(t('docker.catalog.hostPortUserPassword', { host: code('127.0.0.1'), port: code('{{port:3308}}'), user: code('app'), password: code('{{secret:MARIA}}') })); },
    },
    {
        key: 'mongo', name: 'MongoDB 7', category: 'database', icon: 'bi bi-database', color: 'text-emerald-700',
        get description() { return t('docker.catalog.mongo.description'); },
        spec: {
            image: 'mongo:7', name: 'mongo',
            ports: [{ host: '{{port:27017}}', container: 27017 }],
            env: [{ key: 'MONGO_INITDB_ROOT_USERNAME', value: 'admin' }, { key: 'MONGO_INITDB_ROOT_PASSWORD', value: '{{secret:MONGO}}' }],
            volumes: [{ source: 'mongo-data', target: '/data/db' }],
        },
        get note() { return connect(code('mongodb://admin:{{secret:MONGO}}@127.0.0.1:{{port:27017}}')); },
    },
    {
        key: 'elasticsearch', name: 'Elasticsearch 8', category: 'search', icon: 'bi bi-search', color: 'text-teal-600',
        get description() { return t('docker.catalog.elasticsearch.description'); },
        spec: {
            image: 'docker.elastic.co/elasticsearch/elasticsearch:8.15.3', name: 'elasticsearch',
            ports: [{ host: '{{port:9200}}', container: 9200 }],
            env: [
                { key: 'discovery.type', value: 'single-node' },
                { key: 'xpack.security.enabled', value: 'false' },
                { key: 'ES_JAVA_OPTS', value: '-Xms512m -Xmx512m' },
            ],
            volumes: [{ source: 'elasticsearch-data', target: '/usr/share/elasticsearch/data' }],
            memory: '1g',
        },
        get note() { return `${connect(t('docker.catalog.elasticsearch.connect', { url: code('http://127.0.0.1:{{port:9200}}') }))} ${t('docker.catalog.elasticsearch.note', { command: code('sudo sysctl -w vm.max_map_count=262144') })}`; },
    },
    {
        key: 'meilisearch', name: 'Meilisearch', category: 'search', icon: 'bi bi-search-heart', color: 'text-pink-600',
        get description() { return t('docker.catalog.meilisearch.description'); },
        spec: {
            image: 'getmeili/meilisearch:v1.11', name: 'meilisearch',
            ports: [{ host: '{{port:7700}}', container: 7700 }],
            env: [{ key: 'MEILI_MASTER_KEY', value: '{{secret:MEILI}}' }, { key: 'MEILI_ENV', value: 'production' }],
            volumes: [{ source: 'meili-data', target: '/meili_data' }],
        },
        get note() { return connect(t('docker.catalog.meilisearch.note', { url: code('http://127.0.0.1:{{port:7700}}'), key: code('{{secret:MEILI}}') })); },
    },
    {
        key: 'typesense', name: 'Typesense', category: 'search', icon: 'bi bi-search', color: 'text-indigo-600',
        get description() { return t('docker.catalog.typesense.description'); },
        spec: {
            image: 'typesense/typesense:27.1', name: 'typesense',
            ports: [{ host: '{{port:8108}}', container: 8108 }],
            volumes: [{ source: 'typesense-data', target: '/data' }],
            command: ['--data-dir', '/data', '--api-key={{secret:TS}}', '--enable-cors'],
        },
        get note() { return connect(t('docker.catalog.urlApiKey', { url: code('http://127.0.0.1:{{port:8108}}'), key: code('{{secret:TS}}') })); },
    },
    {
        key: 'qdrant', name: 'Qdrant', category: 'search', icon: 'bi bi-bounding-box', color: 'text-rose-600',
        get description() { return t('docker.catalog.qdrant.description'); },
        spec: {
            image: 'qdrant/qdrant:latest', name: 'qdrant',
            ports: [{ host: '{{port:6333}}', container: 6333 }],
            env: [{ key: 'QDRANT__SERVICE__API_KEY', value: '{{secret:QDRANT}}' }],
            volumes: [{ source: 'qdrant-data', target: '/qdrant/storage' }],
        },
        get note() { return connect(t('docker.catalog.urlApiKey', { url: code('http://127.0.0.1:{{port:6333}}'), key: code('{{secret:QDRANT}}') })); },
    },
    {
        key: 'minio', name: 'MinIO', category: 'storage', icon: 'bi bi-bucket', color: 'text-red-700',
        get description() { return t('docker.catalog.minio.description'); },
        spec: {
            image: 'minio/minio:latest', name: 'minio',
            ports: [{ host: '{{port:9000}}', container: 9000 }, { host: '{{port:9001}}', container: 9001 }],
            env: [{ key: 'MINIO_ROOT_USER', value: 'admin' }, { key: 'MINIO_ROOT_PASSWORD', value: '{{secret:MINIO}}' }],
            volumes: [{ source: 'minio-data', target: '/data' }],
            command: ['server', '/data', '--console-address', ':9001'],
        },
        get note() { return `${connect(t('docker.catalog.minio.connect', { url: code('http://127.0.0.1:{{port:9000}}'), user: code('admin'), password: code('{{secret:MINIO}}') }))} ${t('docker.catalog.minio.note', { port: '{{port:9001}}' })}`; },
    },
    {
        key: 'rabbitmq', name: 'RabbitMQ', category: 'storage', icon: 'bi bi-envelope-paper', color: 'text-orange-500',
        get description() { return t('docker.catalog.rabbitmq.description'); },
        spec: {
            image: 'rabbitmq:3-management-alpine', name: 'rabbitmq',
            ports: [{ host: '{{port:5672}}', container: 5672 }, { host: '{{port:15672}}', container: 15672 }],
            env: [{ key: 'RABBITMQ_DEFAULT_USER', value: 'admin' }, { key: 'RABBITMQ_DEFAULT_PASS', value: '{{secret:RABBIT}}' }],
            volumes: [{ source: 'rabbitmq-data', target: '/var/lib/rabbitmq' }],
        },
        get note() { return connect(t('docker.catalog.rabbitmq.note', { url: code('amqp://admin:{{secret:RABBIT}}@127.0.0.1:{{port:5672}}'), port: '{{port:15672}}' })); },
    },
    {
        key: 'memcached', name: 'Memcached', category: 'storage', icon: 'bi bi-memory', color: 'text-slate-600',
        get description() { return t('docker.catalog.memcached.description'); },
        spec: { image: 'memcached:1.6-alpine', name: 'memcached', ports: [{ host: '{{port:11211}}', container: 11211 }], command: ['memcached', '-m', '128'], memory: '160m' },
        get note() { return connect(code('127.0.0.1:{{port:11211}}')); },
    },
    {
        key: 'uptime-kuma', name: 'Uptime Kuma', category: 'tools', icon: 'bi bi-activity', color: 'text-emerald-600',
        get description() { return t('docker.catalog.uptimeKuma.description'); },
        spec: { image: 'louislam/uptime-kuma:1', name: 'uptime-kuma', ports: [{ host: '{{port:3001}}', container: 3001 }], volumes: [{ source: 'uptime-kuma-data', target: '/app/data' }] },
        get note() { return t('docker.catalog.uptimeKuma.note', { image: code('louislam/uptime-kuma:1'), port: code('3001') }); },
        web: true,
    },
    {
        key: 'n8n', name: 'n8n', category: 'tools', icon: 'bi bi-diagram-2', color: 'text-rose-500',
        get description() { return t('docker.catalog.n8n.description'); },
        spec: {
            image: 'n8nio/n8n:latest', name: 'n8n',
            ports: [{ host: '{{port:5678}}', container: 5678 }],
            env: [{ key: 'GENERIC_TIMEZONE', value: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' }],
            volumes: [{ source: 'n8n-data', target: '/home/node/.n8n' }],
        },
        get note() { return t('docker.catalog.n8n.note', { image: code('n8nio/n8n:latest'), port: code('5678') }); },
        web: true,
    },
    {
        key: 'adminer', name: 'Adminer', category: 'tools', icon: 'bi bi-table', color: 'text-blue-600',
        get description() { return t('docker.catalog.adminer.description'); },
        spec: { image: 'adminer:latest', name: 'adminer', ports: [{ host: '{{port:8081}}', container: 8080 }] },
        get note() { return t('docker.catalog.adminer.note', { more: t('docker.form.more'), network: t('docker.form.network') }); },
        web: true,
    },
    {
        key: 'mailpit', name: 'Mailpit', category: 'tools', icon: 'bi bi-mailbox', color: 'text-cyan-600',
        get description() { return t('docker.catalog.mailpit.description'); },
        spec: { image: 'axllent/mailpit:latest', name: 'mailpit', ports: [{ host: '{{port:1025}}', container: 1025 }, { host: '{{port:8025}}', container: 8025 }] },
        get note() { return connect(t('docker.catalog.mailpit.note', { address: code('127.0.0.1:{{port:1025}}'), port: '{{port:8025}}' })); },
    },
    {
        key: 'gitea', name: 'Gitea', category: 'tools', icon: 'bi bi-git', color: 'text-green-700',
        get description() { return t('docker.catalog.gitea.description'); },
        spec: { image: 'gitea/gitea:1.22', name: 'gitea', ports: [{ host: '{{port:3000}}', container: 3000 }], volumes: [{ source: 'gitea-data', target: '/data' }] },
        get note() { return t('docker.catalog.gitea.note', { image: code('gitea/gitea:1.22'), port: code('3000') }); },
        web: true,
    },
    {
        key: 'metabase', name: 'Metabase', category: 'tools', icon: 'bi bi-bar-chart', color: 'text-blue-500',
        get description() { return t('docker.catalog.metabase.description'); },
        spec: { image: 'metabase/metabase:latest', name: 'metabase', ports: [{ host: '{{port:3002}}', container: 3000 }], volumes: [{ source: 'metabase-data', target: '/metabase-data' }], env: [{ key: 'MB_DB_FILE', value: '/metabase-data/metabase.db' }], memory: '1g' },
        get note() { return t('docker.catalog.metabase.note'); },
        web: true,
    },
    {
        key: 'nginx', name: 'Nginx (static files)', category: 'cms', icon: 'bi bi-filetype-html', color: 'text-green-600',
        get description() { return t('docker.catalog.nginx.description'); },
        spec: { image: 'nginx:alpine', name: 'static-site', ports: [{ host: '{{port:8082}}', container: 80 }], volumes: [{ source: '/home/USER/public_html', target: '/usr/share/nginx/html', read_only: true }] },
        get note() { return t('docker.catalog.nginx.note'); },
    },
];

export const stackTemplates = [
    {
        key: 'elastic-kibana', name: 'Elasticsearch + Kibana', category: 'search', icon: 'bi bi-search', color: 'text-teal-600',
        get description() { return t('docker.catalog.elasticKibana.description'); },
        stackName: 'elastic',
        compose: `services:
  elasticsearch:
    image: docker.elastic.co/elasticsearch/elasticsearch:\${ES_VERSION}
    environment:
      - discovery.type=single-node
      - xpack.security.enabled=false
      - ES_JAVA_OPTS=-Xms\${ES_HEAP} -Xmx\${ES_HEAP}
    volumes:
      - es-data:/usr/share/elasticsearch/data
    ports:
      - "127.0.0.1:\${ES_PORT}:9200"
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "curl -fs http://localhost:9200/_cluster/health || exit 1"]
      interval: 15s
      retries: 20

  kibana:
    image: docker.elastic.co/kibana/kibana:\${ES_VERSION}
    environment:
      - ELASTICSEARCH_HOSTS=http://elasticsearch:9200
    ports:
      - "127.0.0.1:\${KIBANA_PORT}:5601"
    depends_on:
      elasticsearch:
        condition: service_healthy
    restart: unless-stopped

volumes:
  es-data:
`,
        env: 'ES_VERSION=8.15.3\nES_HEAP=512m\nES_PORT={{port:9200}}\nKIBANA_PORT={{port:5601}}\n',
        get note() { return t('docker.catalog.elasticKibana.note', { internal: code('http://elasticsearch:9200'), url: code('http://127.0.0.1:{{port:9200}}'), port: '{{port:5601}}' }); },
    },
    {
        key: 'wordpress', name: 'WordPress + MySQL', category: 'cms', icon: 'bi bi-wordpress', color: 'text-blue-700',
        get description() { return t('docker.catalog.wordpress.description'); },
        stackName: 'wordpress',
        compose: `services:
  wordpress:
    image: wordpress:6-apache
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: \${DB_PASSWORD}
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - wp-content:/var/www/html
    ports:
      - "127.0.0.1:\${WP_PORT}:80"
    depends_on:
      - db
    restart: unless-stopped

  db:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: \${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: \${DB_ROOT_PASSWORD}
    volumes:
      - db-data:/var/lib/mysql
    restart: unless-stopped

volumes:
  wp-content:
  db-data:
`,
        env: 'WP_PORT={{port:8085}}\nDB_PASSWORD={{secret}}\nDB_ROOT_PASSWORD={{secret}}\n',
        get note() { return t('docker.catalog.wordpress.note', { port: '{{port:8085}}' }); },
        web: true,
    },
    {
        key: 'ghost', name: 'Ghost + MySQL', category: 'cms', icon: 'bi bi-journal-richtext', color: 'text-slate-700',
        get description() { return t('docker.catalog.ghost.description'); },
        stackName: 'ghost',
        compose: `services:
  ghost:
    image: ghost:5-alpine
    environment:
      url: \${GHOST_URL}
      database__client: mysql
      database__connection__host: db
      database__connection__user: ghost
      database__connection__password: \${DB_PASSWORD}
      database__connection__database: ghost
    volumes:
      - ghost-content:/var/lib/ghost/content
    ports:
      - "127.0.0.1:\${GHOST_PORT}:2368"
    depends_on:
      - db
    restart: unless-stopped

  db:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: ghost
      MYSQL_USER: ghost
      MYSQL_PASSWORD: \${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: \${DB_ROOT_PASSWORD}
    volumes:
      - db-data:/var/lib/mysql
    restart: unless-stopped

volumes:
  ghost-content:
  db-data:
`,
        env: 'GHOST_URL=https://blog.example.com\nGHOST_PORT={{port:2368}}\nDB_PASSWORD={{secret}}\nDB_ROOT_PASSWORD={{secret}}\n',
        get note() { return t('docker.catalog.ghost.note', { variable: code('GHOST_URL'), port: '{{port:2368}}' }); },
        web: true,
    },
    {
        key: 'nextcloud', name: 'Nextcloud', category: 'storage', icon: 'bi bi-cloud', color: 'text-sky-600',
        get description() { return t('docker.catalog.nextcloud.description'); },
        stackName: 'nextcloud',
        compose: `services:
  app:
    image: nextcloud:apache
    environment:
      MYSQL_HOST: db
      MYSQL_DATABASE: nextcloud
      MYSQL_USER: nextcloud
      MYSQL_PASSWORD: \${DB_PASSWORD}
      REDIS_HOST: redis
      NEXTCLOUD_TRUSTED_DOMAINS: \${DOMAIN}
      OVERWRITEPROTOCOL: https
    volumes:
      - nextcloud:/var/www/html
    ports:
      - "127.0.0.1:\${NC_PORT}:80"
    depends_on:
      - db
      - redis
    restart: unless-stopped

  db:
    image: mariadb:11
    command: --transaction-isolation=READ-COMMITTED
    environment:
      MARIADB_DATABASE: nextcloud
      MARIADB_USER: nextcloud
      MARIADB_PASSWORD: \${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: \${DB_ROOT_PASSWORD}
    volumes:
      - db-data:/var/lib/mysql
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    restart: unless-stopped

volumes:
  nextcloud:
  db-data:
`,
        env: 'DOMAIN=cloud.example.com\nNC_PORT={{port:8086}}\nDB_PASSWORD={{secret}}\nDB_ROOT_PASSWORD={{secret}}\n',
        get note() { return t('docker.catalog.nextcloud.note', { variable: code('DOMAIN'), port: '{{port:8086}}' }); },
        web: true,
    },
    {
        key: 'gitea-pg', name: 'Gitea + PostgreSQL', category: 'tools', icon: 'bi bi-git', color: 'text-green-700',
        get description() { return t('docker.catalog.giteaPg.description'); },
        stackName: 'gitea',
        compose: `services:
  gitea:
    image: gitea/gitea:1.22
    environment:
      GITEA__database__DB_TYPE: postgres
      GITEA__database__HOST: db:5432
      GITEA__database__NAME: gitea
      GITEA__database__USER: gitea
      GITEA__database__PASSWD: \${DB_PASSWORD}
    volumes:
      - gitea-data:/data
    ports:
      - "127.0.0.1:\${GITEA_PORT}:3000"
      - "\${SSH_PORT}:22"
    depends_on:
      - db
    restart: unless-stopped

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: gitea
      POSTGRES_PASSWORD: \${DB_PASSWORD}
      POSTGRES_DB: gitea
    volumes:
      - db-data:/var/lib/postgresql/data
    restart: unless-stopped

volumes:
  gitea-data:
  db-data:
`,
        env: 'GITEA_PORT={{port:3000}}\nSSH_PORT={{port:2222}}\nDB_PASSWORD={{secret}}\n',
        get note() { return t('docker.catalog.giteaPg.note', { port: '{{port:3000}}', sshPort: '{{port:2222}}' }); },
        web: true,
    },
    {
        key: 'n8n-pg', name: 'n8n + PostgreSQL', category: 'tools', icon: 'bi bi-diagram-2', color: 'text-rose-500',
        get description() { return t('docker.catalog.n8nPg.description'); },
        stackName: 'n8n',
        compose: `services:
  n8n:
    image: n8nio/n8n:latest
    environment:
      DB_TYPE: postgresdb
      DB_POSTGRESDB_HOST: db
      DB_POSTGRESDB_DATABASE: n8n
      DB_POSTGRESDB_USER: n8n
      DB_POSTGRESDB_PASSWORD: \${DB_PASSWORD}
      N8N_HOST: \${DOMAIN}
      N8N_PROTOCOL: https
      WEBHOOK_URL: https://\${DOMAIN}/
      GENERIC_TIMEZONE: \${TZ}
    volumes:
      - n8n-data:/home/node/.n8n
    ports:
      - "127.0.0.1:\${N8N_PORT}:5678"
    depends_on:
      - db
    restart: unless-stopped

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: n8n
      POSTGRES_PASSWORD: \${DB_PASSWORD}
      POSTGRES_DB: n8n
    volumes:
      - db-data:/var/lib/postgresql/data
    restart: unless-stopped

volumes:
  n8n-data:
  db-data:
`,
        env: `DOMAIN=n8n.example.com\nN8N_PORT={{port:5678}}\nTZ=${Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'}\nDB_PASSWORD={{secret}}\n`,
        get note() { return t('docker.catalog.n8nPg.note', { variable: code('DOMAIN'), port: '{{port:5678}}' }); },
        web: true,
    },
    {
        key: 'umami', name: 'Umami analytics', category: 'tools', icon: 'bi bi-graph-up', color: 'text-indigo-600',
        get description() { return t('docker.catalog.umami.description'); },
        stackName: 'umami',
        compose: `services:
  umami:
    image: ghcr.io/umami-software/umami:postgresql-latest
    environment:
      DATABASE_URL: postgresql://umami:\${DB_PASSWORD}@db:5432/umami
      APP_SECRET: \${APP_SECRET}
    ports:
      - "127.0.0.1:\${UMAMI_PORT}:3000"
    depends_on:
      - db
    restart: unless-stopped

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: umami
      POSTGRES_PASSWORD: \${DB_PASSWORD}
      POSTGRES_DB: umami
    volumes:
      - db-data:/var/lib/postgresql/data
    restart: unless-stopped

volumes:
  db-data:
`,
        env: 'UMAMI_PORT={{port:3003}}\nDB_PASSWORD={{secret}}\nAPP_SECRET={{secret}}\n',
        get note() { return t('docker.catalog.umami.note', { port: '{{port:3003}}', user: code('admin'), password: code('umami') }); },
        web: true,
    },
    {
        key: 'metabase-pg', name: 'Metabase + PostgreSQL', category: 'tools', icon: 'bi bi-bar-chart', color: 'text-blue-500',
        get description() { return t('docker.catalog.metabasePg.description'); },
        stackName: 'metabase',
        compose: `services:
  metabase:
    image: metabase/metabase:latest
    environment:
      MB_DB_TYPE: postgres
      MB_DB_DBNAME: metabase
      MB_DB_PORT: 5432
      MB_DB_USER: metabase
      MB_DB_PASS: \${DB_PASSWORD}
      MB_DB_HOST: db
    ports:
      - "127.0.0.1:\${MB_PORT}:3000"
    depends_on:
      - db
    restart: unless-stopped

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: metabase
      POSTGRES_PASSWORD: \${DB_PASSWORD}
      POSTGRES_DB: metabase
    volumes:
      - db-data:/var/lib/postgresql/data
    restart: unless-stopped

volumes:
  db-data:
`,
        env: 'MB_PORT={{port:3002}}\nDB_PASSWORD={{secret}}\n',
        get note() { return t('docker.catalog.metabasePg.note', { port: '{{port:3002}}' }); },
        web: true,
    },
    {
        key: 'mongo-express', name: 'MongoDB + Mongo Express', category: 'database', icon: 'bi bi-database', color: 'text-emerald-700',
        get description() { return t('docker.catalog.mongoExpress.description'); },
        stackName: 'mongo',
        compose: `services:
  mongo:
    image: mongo:7
    environment:
      MONGO_INITDB_ROOT_USERNAME: admin
      MONGO_INITDB_ROOT_PASSWORD: \${MONGO_PASSWORD}
    volumes:
      - mongo-data:/data/db
    ports:
      - "127.0.0.1:\${MONGO_PORT}:27017"
    restart: unless-stopped

  mongo-express:
    image: mongo-express:latest
    environment:
      ME_CONFIG_MONGODB_URL: mongodb://admin:\${MONGO_PASSWORD}@mongo:27017/
      ME_CONFIG_BASICAUTH_USERNAME: admin
      ME_CONFIG_BASICAUTH_PASSWORD: \${UI_PASSWORD}
    ports:
      - "127.0.0.1:\${UI_PORT}:8081"
    depends_on:
      - mongo
    restart: unless-stopped

volumes:
  mongo-data:
`,
        env: 'MONGO_PORT={{port:27017}}\nUI_PORT={{port:8087}}\nMONGO_PASSWORD={{secret}}\nUI_PASSWORD={{secret}}\n',
        get note() { return t('docker.catalog.mongoExpress.note', { url: code('mongodb://admin:PASSWORD@127.0.0.1:{{port:27017}}'), user: code('admin'), port: '{{port:8087}}' }); },
    },
    {
        key: 'redis-insight', name: 'Redis + RedisInsight', category: 'database', icon: 'bi bi-lightning-charge', color: 'text-red-600',
        get description() { return t('docker.catalog.redisInsight.description'); },
        stackName: 'redis',
        compose: `services:
  redis:
    image: redis:7-alpine
    command: redis-server --appendonly yes --requirepass \${REDIS_PASSWORD}
    volumes:
      - redis-data:/data
    ports:
      - "127.0.0.1:\${REDIS_PORT}:6379"
    restart: unless-stopped

  insight:
    image: redis/redisinsight:latest
    volumes:
      - insight-data:/data
    ports:
      - "127.0.0.1:\${UI_PORT}:5540"
    restart: unless-stopped

volumes:
  redis-data:
  insight-data:
`,
        env: 'REDIS_PORT={{port:6380}}\nUI_PORT={{port:5540}}\nREDIS_PASSWORD={{secret}}\n',
        get note() { return t('docker.catalog.redisInsight.note', { host: code('redis'), port: code('6379') }); },
    },
];

/** Fills the placeholders in every string of a template, consistently. */
export function instantiate(template, usedPorts) {
    const secrets = {};
    const ports = {};
    const fill = (text) => String(text)
        .replace(/\{\{secret(?::([A-Z0-9_]+))?\}\}/g, (_, name) => {
            if (!name) return randomSecret();
            secrets[name] ??= randomSecret();
            return secrets[name];
        })
        .replace(/\{\{port:(\d+)\}\}/g, (_, preferred) => {
            ports[preferred] ??= freePort(Number(preferred), usedPorts);
            return String(ports[preferred]);
        });
    const walk = (value) => {
        if (typeof value === 'string') return fill(value);
        if (Array.isArray(value)) return value.map(walk);
        if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, walk(v)]));
        return value;
    };
    const filled = walk(template);
    // Port fields must be numbers again.
    if (filled.spec?.ports) filled.spec.ports = filled.spec.ports.map((p) => ({ protocol: 'tcp', public: false, ...p, host: Number(p.host), container: Number(p.container) }));
    return filled;
}
