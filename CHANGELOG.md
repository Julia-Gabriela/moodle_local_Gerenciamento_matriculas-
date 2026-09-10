# Histórico de versões

## 1.2.1 — 2026-09-02

- Botão da seleção de alunos fixado abaixo do campo de autocomplete.
- Nome grande do aluno removido da tela de escolha da operação; a identificação permanece na tabela.
- Limite de inclusão ampliado para 60 salas por operação.
- Limite de retirada/suspensão ampliado para 300 inscrições por operação, suficiente para 15 alunos com 19 disciplinas.
- O tamanho de cada requisição interna permanece limitado a 15 alterações para reduzir risco de Gateway Timeout.

## 1.2.0 — 2026-09-02

- Campos de busca de alunos e turmas ampliados, com botões posicionados abaixo dos campos.
- Textos administrativos simplificados para mostrar apenas os limites de 15 alunos e 35 salas/inscrições.
- Alunos selecionados apresentados em tabelas em todas as etapas.
- Cada aluno possui um botão próprio **Ver perfil**, ao lado do status da conta.
- Campo duplicado de alunos removido da etapa de pesquisa de salas.
- Inclusão concluída passa a mostrar os botões **Voltar ao Gerenciamento de matrículas** e **Nova operação**.
- Remoção e suspensão liberadas para vários alunos após aviso e confirmação.
- Retiradas múltiplas limitadas a 35 inscrições e processadas sequencialmente em lotes internos de até 15.
- Consulta das inscrições de até 15 alunos consolidada em uma única busca ao banco.

## 1.1.1 — 2026-09-02

- Seleção múltipla movida para a primeira tela do Gerenciamento de matrículas.
- Clicar em uma sugestão de aluno agora cria uma etiqueta e não redireciona automaticamente.
- A próxima tela só é aberta pelo botão **Continuar com alunos selecionados**.
- A tela de operações lista todos os alunos escolhidos e encaminha o lote completo para a inclusão.
- Remoção e suspensão continuam limitadas a um aluno por vez para evitar alterações acidentais em massa.
- Pesquisa sem JavaScript também permite marcar vários resultados antes de continuar.

## 1.1.0 — 2026-09-02

- Inclusão simultânea de até 15 alunos nas mesmas salas.
- Autocomplete múltiplo de alunos com etiquetas removíveis na aba **Adicionar em salas**.
- Limite ampliado de 20 para 35 salas por operação.
- Confirmação apresenta alunos, salas e o total máximo de combinações.
- Até 525 combinações são divididas automaticamente em lotes internos de no máximo 15 matrículas.
- Processamento sequencial com barra de progresso e resultado separado por aluno.
- Em caso de falha de comunicação, a fila é interrompida sem repetição automática.
- Consultas de cursos, inscrições existentes e métodos manuais consolidadas por lote.
- Salas em que todos os alunos já estão inscritos ficam indisponíveis; inscrições parciais são identificadas.
- Pré-validação ampliada para bloquear a função se o processador seguro estiver ausente.

## 1.0.4 — 2026-09-01

- Campo de código alterado para seleção múltipla com etiquetas removíveis.
- É possível acumular até 10 códigos agrupados ou disciplinas específicas antes de pesquisar.
- O botão **Pesquisar salas** reúne os resultados de todos os filtros em uma única tabela.
- Salas que correspondem a mais de um filtro são exibidas somente uma vez.
- A pesquisa combinada usa uma única consulta limitada para os cursos, evitando uma consulta separada por código.
- A seleção de sugestões não envia mais o formulário automaticamente.
- Checkbox, limite de 20 matrículas e confirmação final permanecem obrigatórios.

## 1.0.3 — 2026-09-01

- A primeira sugestão do autocomplete mostra somente o código digitado e pesquisa todas as salas correspondentes.
- As sugestões seguintes representam cursos individuais no formato **código - disciplina**.
- Selecionar uma disciplina individual retorna somente a sala escolhida.
- Checkbox e confirmação continuam obrigatórios antes de qualquer matrícula.
- Pré-validação ampliada para bloquear a inclusão se a busca exata de curso estiver incompleta.

## 1.0.2 — 2026-09-01

- Autocomplete de códigos de turma na aba **Adicionar em salas**.
- Sugestões no formato **código - nome da sala**, por número de identificação, nome breve ou nome completo.
- Selecionar um código executa somente a pesquisa e mantém a seleção por checkbox e confirmação obrigatória.
- Consulta limitada a 20 sugestões e protegida por `moodle/site:configview`.
- Pesquisa tradicional restaurada automaticamente se o serviço dinâmico estiver indisponível.

## 1.0.1 — 2026-09-01

- Autocomplete nativo do Moodle na página **Localizar aluno**.
- Sugestões por nome completo, usuário/CPF ou e-mail, limitadas a 20 resultados.
- Abertura direta do gerenciamento ao selecionar o aluno.
- Pesquisa normal preservada como fallback automático em caso de falha no serviço dinâmico.
- Serviço AJAX protegido pela permissão `moodle/site:configview`.

## 1.0.0 — 2026-09-01

- Novo componente técnico `local_gerenciamentomatriculas`.
- Pasta de instalação `/local/gerenciamentomatriculas`.
- Entrada única **Gerenciamento de matrículas** com escolha entre inclusão e remoção/suspensão.
- Pré-validação de arquivos, classes, funções, serviços e métodos antes de habilitar cada função.
- Falha controlada: se uma dependência estiver ausente, a função é bloqueada antes de alterar dados.
- Limite de 20 salas por envio para reduzir risco de requisições longas e Gateway Timeout.
- Lock não bloqueante por aluno para impedir cliques duplos e operações concorrentes.
- Captura de erros por operação, com mensagem segura e registro técnico no log de depuração.
- Operações acima do limite são recusadas integralmente, sem truncamento silencioso.
- Nova aba **Adicionar em salas** no perfil do aluno.
- Busca por código em nome completo, nome breve e número de identificação.
- Seleção de várias salas por checkbox e confirmação antes da matrícula.
- Matrícula pelo método manual com papel Estudante.
- Proteção contra inscrições duplicadas.
- Bloqueio de salas sem método manual ativo.
- Página administrativa para localizar o aluno.
- Acesso alinhado a quem pode visualizar a Administração do site.
- Pasta obrigatória de idioma inglês incluída.
