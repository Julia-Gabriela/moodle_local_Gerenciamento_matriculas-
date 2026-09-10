# Publicação no GitHub

Nome recomendado para o repositório:

```text
moodle-local_gerenciamentomatriculas
```

Dentro desta pasta, execute:

```bash
git init
git add .
git commit -m "Initial release: local_gerenciamentomatriculas 1.2.1"
git branch -M main
git remote add origin URL_DO_REPOSITORIO
git push -u origin main
```

Ao criar o repositório no GitHub, não solicite a criação automática de README, licença ou `.gitignore`, pois esses arquivos já estão incluídos.

## Criar a release 1.2.1

1. Abra **Releases > Draft a new release**.
2. Crie a tag `v1.2.1`.
3. Use o título `Gerenciamento de matrículas 1.2.1`.
4. Anexe `gerenciamentomatriculas-1.2.1-install.zip`.
5. Use a seção `1.2.1` do `CHANGELOG.md` como notas da versão.

O ZIP de instalação deve possuir a pasta `gerenciamentomatriculas` na raiz. O nome do componente permanece `local_gerenciamentomatriculas`.
