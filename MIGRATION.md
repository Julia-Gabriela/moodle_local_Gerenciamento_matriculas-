# Substituição do componente anterior

O componente `local_gerenciamentomatriculas` é um plugin novo. O Moodle não o
considera uma atualização de `local_cancelamentomatricula`.

## Ambiente de teste

1. Faça o backup padrão do ambiente.
2. Remova/desinstale o componente antigo `local_cancelamentomatricula`.
3. Confirme que a pasta `/local/cancelamentomatricula` não permanece no servidor.
4. Instale este ZIP, cuja raiz é `gerenciamentomatriculas`.
5. Acesse **Administração do site > Notificações**.
6. Limpe todos os caches.
7. Valide o perfil de um aluno e as duas funções antes de repetir em produção.

O plugin anterior não possui tabelas próprias. Sua desinstalação não deve
apagar matrículas, notas ou cursos do Moodle; ela remove somente o componente,
suas configurações e permissões registradas. A equipe de infraestrutura deve
confirmar esse comportamento no ambiente de teste antes da produção.

Se os dois componentes permanecerem instalados ao mesmo tempo, poderão surgir
dois atalhos de gerenciamento no perfil do aluno.
