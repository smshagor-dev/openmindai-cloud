FROM mcr.microsoft.com/playwright:v1.56.1-noble
WORKDIR /opt/chromium-reader
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund && chown -R pwuser:pwuser /opt/chromium-reader
COPY --chown=pwuser:pwuser server.mjs ./
USER pwuser
ENV HOST=0.0.0.0 PORT=3107
EXPOSE 3107
CMD ["node", "server.mjs"]
