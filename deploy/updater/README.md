# 宿主机更新器

Admin 的“版本更新”和 MCP 使用相同的持久化任务接口。用户后台 `dk_theme`、管理后台 `xboard-admin`、后端 `xboard` 分别选择准确版本；只更新选中的组件。节点以独立二进制安装或容器实例为单位，不以入站为单位。

更新器是 `xbctl updater` 提供的独立 Linux root 服务，不运行在被替换的节点或面板进程内。支持 amd64/arm64，节点支持 systemd、Docker、Compose，面板组件支持 Docker/Compose。

## 首次接入

后端容器必须能解析并通过 HTTPS 访问 `api.github.com`、`github.com` 及其发布附件下载域名，才能检测与校验发布版本。仅连接 `internal: true` 的 Docker 网络会使 WebUI 版本检测失败。仓库的 staging/production Compose 为后端配置独立的 `xboard-egress` bridge 网络，保留原内部网络；该出站网络不发布宿主端口。旧部署接入更新器时也要补齐并持久化这一网络配置，不能只验证宿主机能访问 GitHub。更新器所在宿主机还需能访问 GHCR 和 GitHub 发布附件。

首次接入需先手动安装含更新任务迁移、`update:executor`、`update:database` 和 `.docker/update-control.sh` 的后端版本，以及含 `updater` 子命令的自有 `xbctl` 正式/开发版本。不能用尚未接入的旧面板完成这次引导安装。后端目标镜像还必须包含 `/etc/xboard-update-protocol` 标记 `1`；更新器拉取后以无网络、只读的一次性容器读取标记，不启动应用。缺少标记的历史镜像在停止原实例前拒绝。之后由面板或 MCP 手动选择已发布版本，不由发布工作流自动部署。

1. 在后端容器内为宿主机签发专用凭据：

   ```sh
   # 面板宿主机，仅有一个 panel 执行器。
   docker exec xboard-app php artisan update:executor panel
   # 节点宿主机，ID 来自服务器管理；同机多个实例共用此执行器。
   docker exec xboard-app php artisan update:executor node --machine-id=123
   ```

   返回的 `token` 只保存到对应宿主机 `/etc/xboard-updater/token`，权限 `0600`，不要放到仓库、聊天、日志或节点配置。它与 Node 的连接 Token、MCP Key 相互独立。`--rotate` 更换凭据，`--disable` 禁止继续领取和上报；轮换时同步替换宿主机凭据文件。

2. 创建 root 所有的 `/etc/xboard-updater`（`0700`）和 `/var/lib/xboard-updater`（`0700`）。以本目录 `config.sample.json` 或 Node 仓库 `updater.sample.json` 为模板，修改面板 URL、真实安装实例、端口和健康地址。配置文件权限 `0600`，父目录不得允许其他用户写入。面板 URL 使用 HTTPS；仅本机回环测试允许 HTTP。

3. 面板后端还需将本目录 `panel-hook.sh` 安装到 `/etc/xboard-updater/panel-hook.sh`，权限 `0700`；同目录创建 `backend-container` 文件，内容为后端容器的准确名称，例如 `xboard-app`。脚本仅使用这些本机配置，不接受服务端传入 shell 命令。

4. 检查并安装独立服务：

   ```sh
   xbctl updater check --config /etc/xboard-updater/config.json
   xbctl updater install --config /etc/xboard-updater/config.json
   systemctl status xboard-updater.service
   journalctl -u xboard-updater.service -n 50 --no-pager
   ```

   安装会复制独立可执行文件到 `/usr/local/libexec/xboard-updater`。更新节点不会替换该服务的运行程序。首次安装后约 15 秒内可在 Admin 看到实例；升级更新器本身时，先等待任务结束，再安装新 `xbctl`、执行 `updater install` 并重启该服务。

## 安装方式与持久化

- `systemd`：填写单个 Node 的准确 `binary` 和 `service`；同一个二进制或 systemd 服务不能重复登记为多个实例。Node 的 `node`、`machine`、`standalone` 运行模式均以实际安装为界；一个进程管理多个入站仍然是一个更新实例。
- `docker`：只管理填写的准确容器名，保留环境变量、端口、挂载、网络和重启策略；保留旧容器供恢复。不支持 `--rm` 容器和手工静态 IP 容器，后者请改为 Compose 管理。容器可写层中的业务数据不会迁移，配置、证书、数据库和业务文件必须持久化挂载。已有 `--volumes-from` 共享请改为显式挂载，避免依赖被替换的容器。
- `compose`：填写绝对 `compose_file`、原 `compose_project` 和单个 `compose_service`。部署使用独立环境文件时，填写绝对路径 `compose_env_file`（例如 `/home/beihai/docker/xboard/.deploy.env`），每次查询和升级都会加载该文件。仅对该服务执行 `up --no-deps`，不会连带更新其他组件。Compose 使用固定宿主端口；健康地址须能从宿主机直达目标服务，不能只检查另一台反代的静态页面。

Compose 的版本选择持久化在 `/var/lib/xboard-updater/compose-<project>.json`；这个文件包含同项目各组件各自的选择。后续手工启动或运维脚本必须继续加载此文件，否则原始 Compose/.env 的旧镜像会覆盖手动选择：

```sh
docker compose --project-name YOUR_PROJECT \
  --file /absolute/path/compose.yaml \
  --file /var/lib/xboard-updater/compose-YOUR_PROJECT.json up -d
```

## 面板后端维护与恢复

后端必须将 `/www/.docker/.data` 持久化为可写挂载，SQLite 数据库也应放在该目录内。模板适用于单个后端容器内运行 Web、Horizon、WebSocket 和 Caddy 的部署；拆分队列/定时任务/外部写入者的部署需先配置能停止所有写入者的本机维护 hook，不能直接照搬模板。

流程为：读取旧版本和容器快照 → 拉取准确镜像 → 写入维护标记并停止应用写入进程 → 数据库备份 → 替换后端 → 等待 Supervisor 与 Redis 就绪 → 显式迁移 → 数据库验证 → 恢复应用 → 准确版本与 HTTP 健康检查。Compose 返回启动成功不表示容器内依赖已就绪，宿主机 hook 会限时等待，避免迁移或清理缓存过早执行而触发回滚。新容器看到共享维护标记时不会自动执行迁移或启动应用写入者；Redis 仍可运行。后端 API 暂停时，任务回执保存在宿主机，恢复后按序补报。

SQLite 备份使用一致性数据库快照，恢复后验证完整性。MySQL 使用 `mysqldump`/`mysql`，配置用户须有完整备份、迁移和恢复当前专用数据库的权限。恢复会清理该数据库中的表/视图后导入快照，以免失败迁移新增的表残留；不要与其他应用共用这个数据库。自定义事件、存储过程和外部数据库写入者需采用自己的完整备份/恢复 hook。

替换或迁移失败且应用尚未恢复写入时，尝试恢复原容器/二进制及数据库。**应用一旦恢复写入，不再自动回灌旧数据库**；健康失败会标记 `rollback_failed` 并锁住该宿主机的后续任务，防止覆盖新写入。独立 Admin/Theme 和 Node 更新不回退后端数据库。

备份保留在宿主机状态目录及后端 `.docker/.data/update-backups/<task-id>.*`，不自动删除。容器配置快照可能含凭据，必须保持私有权限。清理备份或旧容器前需确认任务结束且不再需要恢复，不能运行会删除数据卷的全局清理命令。

人工恢复时先停止 `xboard-updater.service`，保留 `active.json`、任务目录和数据库备份，核对原版本、维护标记及健康状态。不要删除执行中的日志并重领任务：服务端会标记重复领取，缺失本地日志时拒绝重做。恢复日志后重启服务会进行保守恢复或补报；恢复失败任务经人工验证后，可在后端执行 `php artisan update:executor panel --resume`（节点附 `node --machine-id=123 --resume`）解除锁定。此命令只解锁，不替你恢复容器或数据库。

## API / MCP

Admin 管理路径下：

| 操作 | 用途 |
| --- | --- |
| `GET update/overview?target_kind=panel\|node` | 当前版本、执行器在线/恢复锁、实例和最近任务 |
| `GET update/releases` | 指定组件及 stable/dev 分支的可安装版本与兼容性 |
| `POST update/tasks` | 创建单组件/单实例任务，必须指定准确版本和幂等键 |
| `GET update/task?task_id=<uuid>` | 查询一个任务的进度/结果 |

节点请求附 `machine_id`、`instance_id`；通用参数为 `target_kind`、`component`、`channel`，创建任务还需 `target_version`、`idempotency_key`。默认检查当前分支，手动选择允许跨分支。同机任务串行、同实例不重复排队；同一幂等键重试返回原任务。批量更新为每个实例单独创建任务，不把一台机器的所有实例强制绑成一次升级。

MCP 自动目录中的操作为 `update.overview.get`、`update.releases.get`、`update.task.get`、`update.tasks.post`，属于 `system` 权限域。通过 `xboard_admin_read` 查询；创建必须调用 `xboard_admin_mutate`，携带最新 `expected_change_version` 和 `confirmation: "CONFIRM update.tasks.post"`。沿用管理员校验和操作审计，MCP 不直接操作宿主机。

执行器接口独立为 `POST /api/v2/update-executor/{heartbeat,claim,report}`，只接受专用 Bearer 凭据，并限制为登记的面板或节点宿主机。服务端固定自有 Release 来源和镜像名，不接受任意镜像、URL 或命令。目标仅接受 `vX.Y.Z` 或 `vX.Y.Z-dev.RUN_ID.ATTEMPT`；无有效发布清单的版本不能安装。手动降版仍需满足目标兼容性；后端应用版本降低不等于反向执行数据库迁移，不支持未经验证的跨 schema 降版。
