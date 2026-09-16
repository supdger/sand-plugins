# C05 Webman 真实路由提供方

这是 C05 真实业务链的临时 Webman 插件模板。它把一个已有的
`standalone_work_item` 业务对象挂到真实 Webman 路由，并让
`ApplicationAuthorizationMiddleware` 按“请求方法 + 路由模板”解析 SandIAM
路由绑定。handler 只有在路由、策略和实体范围都允许后才读取对象并写入
`standalone_business_audit`。

该模板默认不会进入宿主。受控验收工具必须在已获授权后把整个目录临时复制为
宿主 `plugin/sand-iam-c05-business/`，注入以下环境变量并重载 Webman：

- `SAND_IAM_C05_ROUTE_PROVIDER_ENABLED=I_CONFIRM_C05_TEMPORARY_ROUTE_PROVIDER`
- `SAND_IAM_C05_ORGANIZATION_CODE`
- `SAND_IAM_C05_APPLICATION_CODE`

验收前必须已存在两张 `standalone_*` 表和本轮业务对象；模板不会建库、建表或
补数据。验收结束后先撤销/清理 SandIAM 夹具，再删除临时插件并重载宿主，最后
删除两张业务表。发布包只携带此模板和说明，不启用该路由。
