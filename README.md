# Gerenciamento de matrículas (`local_gerenciamentomatriculas`)

Plugin local para Moodle 5.0+ que adiciona, no perfil do usuário, a opção **Gerenciamento de matrículas**.

O componente técnico é `local_gerenciamentomatriculas` e a pasta de instalação
é `/local/gerenciamentomatriculas`.

## Autoria

Desenvolvido por **Júlia Gabriela Gomes da Silva**.

Versão pessoal para distribuição em Git, sem marcas, endereços ou configurações de uma instituição específica. Licença GNU GPL v3 ou posterior, conforme os cabeçalhos do código.

## O que ele faz

- Exibe uma entrada única **Gerenciamento de matrículas** no perfil do aluno.
- Ao abrir, permite escolher entre **Inclusão em cursos** e **Remoção ou suspensão**.
- Lista todas as inscrições do aluno em cursos.
- Permite selecionar cursos por checkbox.
- Permite aplicar uma das ações:
  - **Desinscrever dos cursos selecionados**.
  - **Suspender inscrição nos cursos selecionados**.
- Permite marcar a opção **Suspender também a conta do usuário**.
- Pesquisa salas por código no nome completo, nome breve e número de identificação.
- Sugere alunos enquanto o administrador digita nome, usuário ou e-mail.
- Na tela inicial, permite acumular até 15 alunos como etiquetas removíveis.
- Clicar em uma sugestão não muda de página; o gerenciamento só abre pelo botão **Continuar com alunos selecionados**.
- Exibe os alunos selecionados em tabela, com um botão individual para abrir cada perfil.
- Aplica as mesmas salas selecionadas a todos os alunos do lote.
- Sugere códigos de turma no formato **código - nome da sala** enquanto o administrador digita.
- Exibe primeiro apenas o código digitado para pesquisar todas as salas relacionadas.
- Abaixo, exibe cada disciplina individualmente para permitir uma busca específica.
- Permite acumular até 10 códigos ou disciplinas como etiquetas removíveis antes de pesquisar.
- O botão **Pesquisar salas** combina todos os filtros selecionados e não repete uma sala que corresponda a mais de um código.
- Adicionar uma sugestão não pesquisa nem matricula automaticamente.
- Permite selecionar até 35 salas por checkbox e matricular todos os alunos selecionados nelas.
- Permite retirar ou suspender inscrições de vários alunos, com limite de 35 inscrições selecionadas por operação.
- Impede duplicidade quando o aluno já possui inscrição na sala.
- Usa somente métodos de inscrição manual já ativos; não altera a configuração dos cursos.
- Mostra uma tela de confirmação antes de executar.
- Respeita as permissões/métodos de inscrição do Moodle. Alguns métodos, como coorte ou integrações externas, podem não permitir desinscrição/suspensão manual.

## Proteção contra Gateway e falhas incompletas

- Valida dependências, classes, funções, serviços e métodos antes de habilitar cada função.
- Se uma etapa obrigatória estiver ausente, bloqueia a função antes de alterar qualquer matrícula.
- Limita a inclusão a 15 alunos e 35 salas, permitindo até 525 combinações confirmadas de uma vez.
- Limita a retirada/suspensão a 15 alunos e 35 inscrições selecionadas por operação.
- Usa lock não bloqueante por aluno para impedir cliques duplos e requisições concorrentes.
- Captura falhas individuais e devolve uma mensagem controlada, mantendo o restante do Moodle navegável.
- Divide automaticamente as combinações em requisições sequenciais de no máximo 15 matrículas.
- Não usa loops de espera, chamadas recursivas, cron ou tarefa ad hoc.
- Interrompe a fila com mensagem controlada se a comunicação falhar; não repete operações automaticamente.
- Pré-carrega cursos, inscrições existentes e métodos manuais por lote para reduzir consultas ao banco.
- A busca rápida espera a digitação, exige 2 caracteres e retorna no máximo 20 alunos.
- Se o serviço de sugestões falhar, restaura automaticamente a pesquisa normal da página.
- O autocomplete de turmas também exige 2 caracteres, limita a resposta a 20 códigos e possui fallback automático.
- A pesquisa combinada aceita no máximo 10 filtros e reúne todos eles em uma única consulta limitada, evitando uma consulta separada para cada código.

## Instalação

1. Copie a pasta `gerenciamentomatriculas` para:

   ```text
   /local/gerenciamentomatriculas
   ```

2. Acesse o Moodle como administrador.
3. Vá em **Administração do site > Notificações** para concluir a instalação.
4. O acesso é permitido somente a usuários que podem visualizar a área de
   **Administração do site** (`moodle/site:configview`).
5. A capability própria fica disponível em:

   ```text
   Administração do site > Usuários > Permissões > Definir papéis
   ```

   Capability criada:

   ```text
   local/gerenciamentomatriculas:manage
   ```

## Como usar

1. Abra **Gerenciamento de matrículas** pela Administração do site ou pelo perfil de um aluno.
2. Pesquise e acumule até 15 alunos; clique em **Continuar com alunos selecionados** somente quando terminar.
3. Escolha **Adicionar em cursos** ou **Remoção ou suspensão**. Para vários alunos, a retirada exibe um aviso adicional e exige revisão antes da confirmação.
4. Na inclusão, adicione um ou mais códigos, clique em **Pesquisar salas** e marque até 35 salas.
5. Revise a quantidade de alunos, salas e combinações; confirme uma vez e mantenha a página aberta até o fim.
6. Ao terminar, use **Voltar ao Gerenciamento de matrículas** ou **Nova operação**.

## Observação importante

Este componente é um plugin novo e não atualiza automaticamente o antigo
`local_cancelamentomatricula`. Se o plugin antigo estiver instalado, ambos serão
carregados pelo Moodle e poderão exibir atalhos duplicados. Depois de validar
esta versão no ambiente de teste, remova o componente antigo antes da implantação
definitiva.

Desinscrever é uma ação mais definitiva e pode afetar vínculo, grupos, notas e dados de participação no curso. Para preservar histórico, prefira suspender a inscrição.

Na inclusão em lote, salas sem método de inscrição manual ativo são exibidas,
mas não podem ser selecionadas. O plugin não cria nem habilita métodos de
inscrição automaticamente.

## Uso em um repositório Git

O conteúdo desta pasta deve ficar na raiz do repositório. Para instalar no Moodle, use a pasta `gerenciamentomatriculas` dentro de `local`. Os módulos JavaScript em `amd/build` fazem parte da distribuição e devem ser versionados junto com `amd/src`.

## Validação desta edição

Conferidos o conteúdo do ZIP, a identidade do componente e a ausência de referências institucionais. As funções da versão 1.2.0 foram preservadas. Esta edição não foi executada em uma instalação Moodle; valide inclusão, remoção e suspensão em ambiente de teste antes de usar em produção.
