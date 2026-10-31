# SQLite 流量统计与重置

本 fork 保留以下线上修复，构建镜像时自动包含，无须修改运行容器：

- `TrafficFetchJob` 将倍率计算结果转换为整数，避免 SQLite 在 INTEGER 亲和性列中存储 REAL，导致严格要求整数流量的插件拒绝数据。
- 自动重置在事务内获取写锁后重读用户和重置时间，避免旧模型或并发回调重复重置。
- SQLite 不支持 `SELECT FOR UPDATE`，因此先执行不修改任何行的 DML 获取写锁。
- 购买触发的重置保留尚未保存的套餐、到期和配额字段，流量及重置计数以数据库当前值为准。
- 缓存失效及插件回调等待最外层事务提交。回调异常不会把已提交的重置变成可重试的失败。

## 隔离回归

```sh
php tests/Regression/sqlite_traffic.php
php tests/Regression/subscription_transport_filters.php
php tests/Regression/reality_client_defaults.php
```

SQLite 回归自行创建并删除临时 WAL 数据库，使用两个独立进程验证并发重置；需要 `pdo_sqlite` 和 `pcntl`。不读取生产 `.env`、不连接 Redis、不接触生产数据库。

## 部署与备份

WAL 允许读取与写入并行，但 SQLite 同时仍只有一个写入者。应用配置的 busy timeout 只会等待锁，不能消除写入瓶颈。应监测锁错误、队列延迟和磁盘使用量；持续写入竞争时再安排迁移 MySQL 或 PostgreSQL。

活动数据库用 SQLite 在线备份 API 创建一致性快照；不要单独复制主数据库文件而遗漏 WAL。保留完整旧镜像和 Compose 配置。新旧 schema 一致时，代码回滚应复用当前数据库，避免恢复旧快照而丢掉切换后的业务写入。

独立 Web/Horizon 部署可显式使用 `entrypoint: []` 并保留原命令，避免镜像默认入口自动执行 `xboard:update`。先单独核对迁移状态；`entrypoint: null` 会沿用镜像入口。保持现有的数据库、Redis、主题、插件及邮件模板持久挂载；镜像已包含的源码修复不再用旧文件覆盖。

参考：[SQLite WAL](https://www.sqlite.org/wal.html)、[适用场景](https://www.sqlite.org/whentouse.html)、[在线备份](https://www.sqlite.org/backup.html)。
